<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Stay;
use App\Models\StayNight;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ReservationRoomPricingService
{
    public function resolveEffectiveNightPrice(Reservation $reservation, ReservationRoom $reservationRoom): float
    {
        $latestNightPrice = $this->latestNightPriceInContractRange($reservation, $reservationRoom);
        if ($latestNightPrice > 0) {
            return $latestNightPrice;
        }

        $pricePerNight = round((float) ($reservationRoom->price_per_night ?? 0), 2);
        if ($pricePerNight > 0) {
            return $pricePerNight;
        }

        $nights = $this->contractNightCount($reservationRoom);
        $subtotal = round((float) ($reservationRoom->subtotal ?? 0), 2);
        if ($subtotal > 0 && $nights > 0) {
            return round($subtotal / $nights, 2);
        }

        if ($reservation->reservationRooms()->count() === 1) {
            $reservationTotal = round((float) ($reservation->total_amount ?? 0), 2);
            if ($reservationTotal > 0 && $nights > 0) {
                return round($reservationTotal / $nights, 2);
            }
        }

        return 0.0;
    }

    /**
     * @return array{nights:int,subtotal:float,price_per_night:float}
     */
    public function syncReservationRoomContractSnapshot(
        Reservation $reservation,
        ReservationRoom $reservationRoom
    ): array {
        [$checkInDate, $checkOutDate] = $this->contractRange($reservationRoom);
        if (!$checkInDate || !$checkOutDate || !$checkOutDate->gt($checkInDate)) {
            return [
                'nights' => max(0, (int) ($reservationRoom->nights ?? 0)),
                'subtotal' => round((float) ($reservationRoom->subtotal ?? 0), 2),
                'price_per_night' => round((float) ($reservationRoom->price_per_night ?? 0), 2),
            ];
        }

        $nights = max(1, $checkInDate->diffInDays($checkOutDate));
        $canonicalNights = $this->canonicalStayNightsByDate($reservation, $reservationRoom, $checkInDate, $checkOutDate);
        $subtotal = round((float) $canonicalNights->sum(static fn (StayNight $night) => (float) ($night->price ?? 0)), 2);
        $latestNightPrice = round(
            (float) ($canonicalNights->sortBy(static fn (StayNight $night) => $night->date?->toDateString() ?? '')
                ->last()?->price ?? 0),
            2
        );

        if ($latestNightPrice <= 0) {
            $latestNightPrice = $this->resolveEffectiveNightPrice($reservation, $reservationRoom);
        }

        $updates = [
            'nights' => $nights,
            'subtotal' => $subtotal,
            'price_per_night' => $latestNightPrice > 0 ? $latestNightPrice : round((float) ($reservationRoom->price_per_night ?? 0), 2),
        ];

        $reservationRoom->fill($updates);
        if ($reservationRoom->isDirty()) {
            $reservationRoom->save();
        }

        return $updates;
    }

    /**
     * @return array{total_amount:float,payments_total:float,sales_debt:float,balance_due:float}
     */
    public function syncReservationFinancialSnapshot(Reservation $reservation): array
    {
        $reservation->loadMissing(['reservationRooms']);

        $totalAmount = round((float) $reservation->reservationRooms->sum(
            static fn (ReservationRoom $room): float => round((float) ($room->subtotal ?? 0), 2)
        ), 2);

        if ($totalAmount <= 0) {
            $totalAmount = round((float) StayNight::query()
                ->where('reservation_id', $reservation->id)
                ->sum('price'), 2);
        }

        $paymentsTotal = round((float) Payment::query()
            ->where('reservation_id', $reservation->id)
            ->sum('amount'), 2);

        $salesDebt = 0.0;
        if (Schema::hasTable('reservation_sales')) {
            $salesDebt = round((float) DB::table('reservation_sales')
                ->where('reservation_id', $reservation->id)
                ->where('is_paid', false)
                ->sum('total'), 2);
        }

        $balanceDue = round(max(0, $totalAmount - $paymentsTotal + $salesDebt), 2);
        $depositAmount = round(max(0, $paymentsTotal), 2);

        $updates = [
            'total_amount' => $totalAmount,
            'deposit_amount' => $depositAmount,
            'balance_due' => $balanceDue,
        ];

        if (Schema::hasTable('payment_statuses')) {
            $paymentStatusCode = $balanceDue <= 0
                ? 'paid'
                : ($paymentsTotal > 0 ? 'partial' : 'pending');

            $paymentStatusId = DB::table('payment_statuses')
                ->where('code', $paymentStatusCode)
                ->value('id');

            if ($paymentStatusId !== null) {
                $updates['payment_status_id'] = (int) $paymentStatusId;
            }
        }

        $reservation->fill($updates);
        if ($reservation->isDirty()) {
            $reservation->save();
        }

        return [
            'total_amount' => $totalAmount,
            'payments_total' => $paymentsTotal,
            'sales_debt' => $salesDebt,
            'balance_due' => $balanceDue,
        ];
    }

    public function syncStayNightPaidFlags(Reservation $reservation): int
    {
        $availableAmount = round(max(0, (float) Payment::query()
            ->where('reservation_id', $reservation->id)
            ->sum('amount')), 2);

        $stayNights = StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->orderBy('date')
            ->orderBy('room_id')
            ->orderBy('id')
            ->get();

        $updated = 0;

        foreach ($stayNights as $stayNight) {
            $nightPrice = round(max(0, (float) ($stayNight->price ?? 0)), 2);
            $shouldBePaid = $nightPrice <= 0 || $availableAmount >= $nightPrice;

            if ($shouldBePaid && $nightPrice > 0) {
                $availableAmount = round(max(0, $availableAmount - $nightPrice), 2);
            }

            if ((bool) $stayNight->is_paid !== $shouldBePaid) {
                $stayNight->update(['is_paid' => $shouldBePaid]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @return array{
     *     reservation_id:int,
     *     room_id:int,
     *     from_date:string,
     *     applied_nights:int,
     *     price_per_night:float,
     *     subtotal:float,
     *     total_amount:float,
     *     balance_due:float,
     *     paid_flags_updated:int
     * }
     */
    public function repairRoomNightPriceFromDate(
        Reservation $reservation,
        ReservationRoom $reservationRoom,
        Carbon $fromDate,
        float $nightPrice
    ): array {
        [$checkInDate, $checkOutDate] = $this->contractRange($reservationRoom);
        if (!$checkInDate || !$checkOutDate || !$checkOutDate->gt($checkInDate)) {
            throw new RuntimeException('La habitacion contractual no tiene un rango valido para reparar.');
        }

        $nightPrice = round(max(0, $nightPrice), 2);
        if ($nightPrice <= 0) {
            throw new RuntimeException('El precio por noche debe ser mayor a 0.');
        }

        $effectiveFrom = $fromDate->copy()->startOfDay();
        if ($effectiveFrom->lt($checkInDate)) {
            $effectiveFrom = $checkInDate->copy();
        }
        if (!$effectiveFrom->lt($checkOutDate)) {
            throw new RuntimeException('La fecha desde no puede ser igual o posterior al checkout contractual.');
        }

        $stay = $this->resolvePreferredStay($reservation, $reservationRoom);
        if (!$stay) {
            throw new RuntimeException('No se encontro una estadia asociada para reparar el precio de noches.');
        }

        $appliedNights = 0;
        $paidFlagsUpdated = 0;
        $financial = [];

        DB::transaction(function () use (
            $reservation,
            $reservationRoom,
            $stay,
            $effectiveFrom,
            $checkOutDate,
            $nightPrice,
            &$appliedNights,
            &$paidFlagsUpdated,
            &$financial
        ): void {
            for ($cursor = $effectiveFrom->copy(); $cursor->lt($checkOutDate); $cursor->addDay()) {
                $dateString = $cursor->toDateString();

                $nightsForDate = StayNight::query()
                    ->where('reservation_id', $reservation->id)
                    ->where('room_id', (int) $reservationRoom->room_id)
                    ->whereDate('date', $dateString)
                    ->orderByDesc('is_paid')
                    ->orderBy('id')
                    ->get();

                /** @var \App\Models\StayNight|null $canonical */
                $canonical = $nightsForDate->first();

                if ($canonical) {
                    $canonical->update([
                        'stay_id' => $stay->id,
                        'reservation_id' => $reservation->id,
                        'room_id' => (int) $reservationRoom->room_id,
                        'price' => $nightPrice,
                    ]);

                    foreach ($nightsForDate->slice(1) as $duplicateNight) {
                        if ((bool) $duplicateNight->is_paid) {
                            throw new RuntimeException(
                                'Existen noches pagadas duplicadas en ' . $dateString . ' para esta habitacion. Revisa antes de reparar.'
                            );
                        }

                        $duplicateNight->delete();
                    }
                } else {
                    StayNight::create([
                        'stay_id' => $stay->id,
                        'reservation_id' => $reservation->id,
                        'room_id' => (int) $reservationRoom->room_id,
                        'date' => $dateString,
                        'price' => $nightPrice,
                        'is_paid' => false,
                    ]);
                }

                $appliedNights++;
            }

            $reservation->refresh()->loadMissing(['reservationRooms']);
            $freshReservationRoom = $reservation->reservationRooms
                ->firstWhere('id', (int) $reservationRoom->id);

            if (!$freshReservationRoom) {
                throw new RuntimeException('No se encontro la habitacion contractual despues de reparar.');
            }

            $this->syncReservationRoomContractSnapshot($reservation, $freshReservationRoom);
            $paidFlagsUpdated = $this->syncStayNightPaidFlags($reservation);
            $financial = $this->syncReservationFinancialSnapshot($reservation);
        }, 3);

        $reservation->refresh()->loadMissing(['reservationRooms']);
        $updatedRoom = $reservation->reservationRooms->firstWhere('id', (int) $reservationRoom->id);
        if (!$updatedRoom) {
            throw new RuntimeException('No fue posible recargar la habitacion reparada.');
        }

        return [
            'reservation_id' => (int) $reservation->id,
            'room_id' => (int) $reservationRoom->room_id,
            'from_date' => $effectiveFrom->toDateString(),
            'applied_nights' => $appliedNights,
            'price_per_night' => round((float) ($updatedRoom->price_per_night ?? 0), 2),
            'subtotal' => round((float) ($updatedRoom->subtotal ?? 0), 2),
            'total_amount' => round((float) ($financial['total_amount'] ?? $reservation->total_amount ?? 0), 2),
            'balance_due' => round((float) ($financial['balance_due'] ?? $reservation->balance_due ?? 0), 2),
            'paid_flags_updated' => $paidFlagsUpdated,
        ];
    }

    private function latestNightPriceInContractRange(Reservation $reservation, ReservationRoom $reservationRoom): float
    {
        [$checkInDate, $checkOutDate] = $this->contractRange($reservationRoom);

        $query = StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->where('room_id', (int) ($reservationRoom->room_id ?? 0))
            ->where('price', '>', 0);

        if ($checkInDate && $checkOutDate && $checkOutDate->gt($checkInDate)) {
            $query->whereDate('date', '>=', $checkInDate->toDateString())
                ->whereDate('date', '<', $checkOutDate->toDateString());
        }

        return round((float) ($query
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->value('price') ?? 0), 2);
    }

    /**
     * @return array{0:\Carbon\Carbon|null,1:\Carbon\Carbon|null}
     */
    private function contractRange(ReservationRoom $reservationRoom): array
    {
        try {
            $checkInDate = !empty($reservationRoom->check_in_date)
                ? Carbon::parse((string) $reservationRoom->check_in_date)->startOfDay()
                : null;
            $checkOutDate = !empty($reservationRoom->check_out_date)
                ? Carbon::parse((string) $reservationRoom->check_out_date)->startOfDay()
                : null;
        } catch (\Throwable) {
            return [null, null];
        }

        return [$checkInDate, $checkOutDate];
    }

    private function contractNightCount(ReservationRoom $reservationRoom): int
    {
        [$checkInDate, $checkOutDate] = $this->contractRange($reservationRoom);
        if (!$checkInDate || !$checkOutDate || !$checkOutDate->gt($checkInDate)) {
            return max(0, (int) ($reservationRoom->nights ?? 0));
        }

        return max(1, $checkInDate->diffInDays($checkOutDate));
    }

    /**
     * @return \Illuminate\Support\Collection<int,\App\Models\StayNight>
     */
    private function canonicalStayNightsByDate(
        Reservation $reservation,
        ReservationRoom $reservationRoom,
        Carbon $checkInDate,
        Carbon $checkOutDate
    ): Collection {
        return StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->where('room_id', (int) $reservationRoom->room_id)
            ->whereDate('date', '>=', $checkInDate->toDateString())
            ->whereDate('date', '<', $checkOutDate->toDateString())
            ->orderByDesc('is_paid')
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->groupBy(static fn (StayNight $night): string => $night->date?->toDateString() ?? '')
            ->map(static fn (Collection $group): StayNight => $group->first())
            ->values();
    }

    private function resolvePreferredStay(Reservation $reservation, ReservationRoom $reservationRoom): ?Stay
    {
        $roomId = (int) ($reservationRoom->room_id ?? 0);
        if ($roomId <= 0) {
            return null;
        }

        return Stay::query()
            ->where('reservation_id', $reservation->id)
            ->where('room_id', $roomId)
            ->orderByRaw('CASE WHEN check_out_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('check_in_at')
            ->orderByDesc('id')
            ->first();
    }
}
