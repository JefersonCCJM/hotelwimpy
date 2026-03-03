<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Stay;
use App\Models\StayNight;
use App\Support\HotelTime;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReservationRetroactiveRecalculationService
{
    /**
     * @var array<string,int|null>
     */
    private array $paymentStatusIds = [];

    /**
     * @param array{
     *     dry_run?:bool,
     *     keep_total?:bool,
     *     without_sales_debt?:bool,
     *     strict_paid_outside_range?:bool,
     *     operational_date?:\Carbon\Carbon
     * } $options
     * @return array<string,mixed>
     */
    public function recalculateReservation(Reservation $reservation, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $keepTotal = (bool) ($options['keep_total'] ?? false);
        $withoutSalesDebt = (bool) ($options['without_sales_debt'] ?? false);
        $strictPaidOutsideRange = (bool) ($options['strict_paid_outside_range'] ?? false);
        $operationalDate = $options['operational_date'] ?? HotelTime::currentOperationalDate();

        if (!$operationalDate instanceof Carbon) {
            $operationalDate = HotelTime::currentOperationalDate();
        }

        $runner = function (bool $persist) use (
            $reservation,
            $dryRun,
            $keepTotal,
            $withoutSalesDebt,
            $strictPaidOutsideRange,
            $operationalDate
        ): array {
            $workingReservation = Reservation::withTrashed()
                ->with(['reservationRooms', 'stays', 'sales'])
                ->find($reservation->id);

            if (!$workingReservation) {
                throw new RuntimeException('No se encontro la reserva para recalcular.');
            }

            $result = [
                'reservation_id' => (int) $workingReservation->id,
                'reservation_code' => (string) ($workingReservation->reservation_code ?? ''),
                'dry_run' => $dryRun,
                'changed' => false,
                'created_stays' => 0,
                'updated_stays' => 0,
                'created_nights' => 0,
                'updated_nights' => 0,
                'deleted_nights' => 0,
                'paid_flags_updated' => 0,
                'preserved_paid_nights' => 0,
                'skipped_rooms' => 0,
                'updated_reservation_row' => false,
                'total_lodging_before' => round((float) ($workingReservation->total_amount ?? 0), 2),
                'total_lodging_after' => round((float) ($workingReservation->total_amount ?? 0), 2),
                'payments_total' => 0.0,
                'sales_debt' => 0.0,
                'balance_due_before' => round((float) ($workingReservation->balance_due ?? 0), 2),
                'balance_due_after' => round((float) ($workingReservation->balance_due ?? 0), 2),
            ];

            $staysByRoom = $this->buildStaysByRoom($workingReservation->stays);
            $activeRoomIds = [];

            foreach ($workingReservation->reservationRooms as $reservationRoom) {
                $roomId = (int) ($reservationRoom->room_id ?? 0);
                $checkInDate = $this->safeParseDate($reservationRoom->check_in_date ?? null);
                $checkOutDate = $this->safeParseDate($reservationRoom->check_out_date ?? null);

                if (
                    $roomId <= 0
                    || !$checkInDate
                    || !$checkOutDate
                    || !$checkOutDate->gt($checkInDate)
                ) {
                    $result['skipped_rooms']++;
                    continue;
                }

                $activeRoomIds[] = $roomId;
                $stay = $staysByRoom[$roomId] ?? null;
                if (!$stay) {
                    $result['created_stays']++;
                    if ($persist) {
                        $stay = $this->createStayForRoomRange(
                            $workingReservation,
                            $reservationRoom,
                            $checkInDate,
                            $checkOutDate,
                            $operationalDate
                        );
                        $staysByRoom[$roomId] = $stay;
                    }
                }

                $sync = $this->syncRoomStayNights(
                    reservation: $workingReservation,
                    reservationRoom: $reservationRoom,
                    stay: $stay,
                    checkInDate: $checkInDate,
                    checkOutDate: $checkOutDate,
                    strictPaidOutsideRange: $strictPaidOutsideRange,
                    persist: $persist
                );

                $result['created_nights'] += (int) ($sync['created_nights'] ?? 0);
                $result['updated_nights'] += (int) ($sync['updated_nights'] ?? 0);
                $result['deleted_nights'] += (int) ($sync['deleted_nights'] ?? 0);
                $result['preserved_paid_nights'] += (int) ($sync['preserved_paid_nights'] ?? 0);

                if ($stay) {
                    $updatedStay = $this->syncStayStatus(
                        stay: $stay,
                        contractCheckOutDate: $checkOutDate,
                        operationalDate: $operationalDate,
                        persist: $persist
                    );
                    $result['updated_stays'] += $updatedStay ? 1 : 0;
                }
            }

            $cleanup = $this->cleanupOrphanRoomNights(
                reservation: $workingReservation,
                activeRoomIds: array_values(array_unique($activeRoomIds)),
                strictPaidOutsideRange: $strictPaidOutsideRange,
                persist: $persist
            );

            $result['deleted_nights'] += (int) ($cleanup['deleted_nights'] ?? 0);
            $result['preserved_paid_nights'] += (int) ($cleanup['preserved_paid_nights'] ?? 0);

            $result['total_lodging_after'] = $this->calculateContractualStayNightsTotal($workingReservation);

            $paidState = $this->rebuildStayNightPaidStateFromPayments(
                reservation: $workingReservation,
                persist: $persist
            );
            $result['paid_flags_updated'] = (int) ($paidState['paid_flags_updated'] ?? 0);

            $financial = $this->syncReservationFinancialColumns(
                reservation: $workingReservation,
                lodgingTotal: $result['total_lodging_after'],
                keepTotal: $keepTotal,
                withoutSalesDebt: $withoutSalesDebt,
                persist: $persist
            );

            $result['payments_total'] = (float) ($financial['payments_total'] ?? 0.0);
            $result['sales_debt'] = (float) ($financial['sales_debt'] ?? 0.0);
            $result['balance_due_after'] = (float) ($financial['balance_due'] ?? 0.0);
            $result['updated_reservation_row'] = (bool) ($financial['updated'] ?? false);

            $result['changed'] = (
                $result['created_stays'] > 0
                || $result['updated_stays'] > 0
                || $result['created_nights'] > 0
                || $result['updated_nights'] > 0
                || $result['deleted_nights'] > 0
                || $result['paid_flags_updated'] > 0
                || $result['updated_reservation_row'] === true
            );

            return $result;
        };

        if ($dryRun) {
            return $runner(false);
        }

        return DB::transaction(fn () => $runner(true), 3);
    }

    /**
     * @param \Illuminate\Support\Collection<int,\App\Models\Stay> $stays
     * @return array<int,\App\Models\Stay>
     */
    private function buildStaysByRoom(Collection $stays): array
    {
        $map = [];

        foreach ($stays as $stay) {
            $roomId = (int) ($stay->room_id ?? 0);
            if ($roomId <= 0) {
                continue;
            }

            if (!isset($map[$roomId])) {
                $map[$roomId] = $stay;
                continue;
            }

            if ($this->isPreferredStay($stay, $map[$roomId])) {
                $map[$roomId] = $stay;
            }
        }

        return $map;
    }

    private function isPreferredStay(Stay $candidate, Stay $current): bool
    {
        $candidateOpen = $candidate->check_out_at === null;
        $currentOpen = $current->check_out_at === null;

        if ($candidateOpen !== $currentOpen) {
            return $candidateOpen;
        }

        $candidateCheckIn = $candidate->check_in_at?->timestamp ?? 0;
        $currentCheckIn = $current->check_in_at?->timestamp ?? 0;

        if ($candidateCheckIn !== $currentCheckIn) {
            return $candidateCheckIn > $currentCheckIn;
        }

        return (int) ($candidate->id ?? 0) > (int) ($current->id ?? 0);
    }

    private function createStayForRoomRange(
        Reservation $reservation,
        ReservationRoom $reservationRoom,
        Carbon $checkInDate,
        Carbon $checkOutDate,
        Carbon $operationalDate
    ): Stay {
        $checkInAt = HotelTime::startOfOperationalDay($checkInDate->copy());
        $currentOperationalDate = $operationalDate->copy()->startOfDay();

        $status = 'active';
        $checkOutAt = null;

        if ($checkOutDate->lt($currentOperationalDate)) {
            $status = 'finished';
            $checkOutAt = Carbon::parse(
                $checkOutDate->toDateString() . ' ' . HotelTime::checkOutTime(),
                HotelTime::timezone()
            );
        } elseif ($checkOutDate->equalTo($currentOperationalDate)) {
            $status = 'pending_checkout';
        }

        return Stay::create([
            'reservation_id' => $reservation->id,
            'room_id' => (int) $reservationRoom->room_id,
            'check_in_at' => $checkInAt,
            'check_out_at' => $checkOutAt,
            'status' => $status,
        ]);
    }

    /**
     * @return array{created_nights:int,updated_nights:int,deleted_nights:int,preserved_paid_nights:int}
     */
    private function syncRoomStayNights(
        Reservation $reservation,
        ReservationRoom $reservationRoom,
        ?Stay $stay,
        Carbon $checkInDate,
        Carbon $checkOutDate,
        bool $strictPaidOutsideRange,
        bool $persist
    ): array {
        $roomId = (int) ($reservationRoom->room_id ?? 0);
        $stayId = $stay ? (int) $stay->id : null;
        $targetDates = $this->buildTargetDateMap($checkInDate, $checkOutDate);
        $nightPrice = $this->resolveNightPrice($reservation, $reservationRoom, $checkInDate, $checkOutDate);

        $counters = [
            'created_nights' => 0,
            'updated_nights' => 0,
            'deleted_nights' => 0,
            'preserved_paid_nights' => 0,
        ];

        /** @var \Illuminate\Support\Collection<int,\App\Models\StayNight> $existingNights */
        $existingNights = StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->where('room_id', $roomId)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        /** @var array<string,\Illuminate\Support\Collection<int,\App\Models\StayNight>> $nightsByDate */
        $nightsByDate = $existingNights->groupBy(function (StayNight $night): string {
            return $this->dateKey($night->date);
        })->all();

        $matchedTargetDates = [];

        foreach ($nightsByDate as $dateKey => $nightsForDate) {
            $isTargetDate = isset($targetDates[$dateKey]);

            if (!$isTargetDate) {
                foreach ($nightsForDate as $night) {
                    if ((bool) $night->is_paid) {
                        if ($strictPaidOutsideRange) {
                            throw new RuntimeException(
                                "Reserva #{$reservation->id}: existe noche pagada fuera de rango contractual ({$dateKey}, habitacion {$roomId})."
                            );
                        }
                        $counters['preserved_paid_nights']++;
                        continue;
                    }

                    $counters['deleted_nights']++;
                    if ($persist) {
                        $night->delete();
                    }
                }
                continue;
            }

            $canonical = $this->pickCanonicalNight($nightsForDate, $stayId);
            $matchedTargetDates[$dateKey] = true;

            foreach ($nightsForDate as $night) {
                if ((int) $night->id === (int) $canonical->id) {
                    continue;
                }

                if ((bool) $night->is_paid) {
                    $counters['preserved_paid_nights']++;
                    continue;
                }

                $counters['deleted_nights']++;
                if ($persist) {
                    $night->delete();
                }
            }

            $updates = [];
            if ((int) $canonical->reservation_id !== (int) $reservation->id) {
                $updates['reservation_id'] = $reservation->id;
            }
            if ((int) $canonical->room_id !== $roomId) {
                $updates['room_id'] = $roomId;
            }
            if ($stayId !== null && (int) $canonical->stay_id !== $stayId) {
                $updates['stay_id'] = $stayId;
            }
            if (abs((float) ($canonical->price ?? 0) - $nightPrice) > 0.009) {
                $updates['price'] = $nightPrice;
            }

            if (!empty($updates)) {
                $counters['updated_nights']++;
                if ($persist) {
                    $canonical->fill($updates);
                    $canonical->save();
                }
            }
        }

        foreach (array_keys($targetDates) as $targetDate) {
            if (isset($matchedTargetDates[$targetDate])) {
                continue;
            }

            $counters['created_nights']++;
            if (!$persist) {
                continue;
            }

            if ($stayId === null) {
                throw new RuntimeException(
                    "Reserva #{$reservation->id}: no fue posible crear stay_night para {$targetDate} porque no hay stay para la habitacion {$roomId}."
                );
            }

            StayNight::create([
                'stay_id' => $stayId,
                'reservation_id' => $reservation->id,
                'room_id' => $roomId,
                'date' => $targetDate,
                'price' => $nightPrice,
                'is_paid' => false,
            ]);
        }

        return $counters;
    }

    /**
     * @param array<int,int> $activeRoomIds
     * @return array{deleted_nights:int,preserved_paid_nights:int}
     */
    private function cleanupOrphanRoomNights(
        Reservation $reservation,
        array $activeRoomIds,
        bool $strictPaidOutsideRange,
        bool $persist
    ): array {
        $query = StayNight::query()->where('reservation_id', $reservation->id);

        if (!empty($activeRoomIds)) {
            $query->whereNotIn('room_id', $activeRoomIds);
        }

        $orphanNights = $query->get();
        $deletedNights = 0;
        $preservedPaidNights = 0;

        foreach ($orphanNights as $night) {
            if ((bool) $night->is_paid) {
                if ($strictPaidOutsideRange) {
                    $dateKey = $this->dateKey($night->date);
                    $roomId = (int) ($night->room_id ?? 0);
                    throw new RuntimeException(
                        "Reserva #{$reservation->id}: existe noche pagada huerfana ({$dateKey}, habitacion {$roomId})."
                    );
                }
                $preservedPaidNights++;
                continue;
            }

            $deletedNights++;
            if ($persist) {
                $night->delete();
            }
        }

        return [
            'deleted_nights' => $deletedNights,
            'preserved_paid_nights' => $preservedPaidNights,
        ];
    }

    private function syncStayStatus(
        Stay $stay,
        Carbon $contractCheckOutDate,
        Carbon $operationalDate,
        bool $persist
    ): bool {
        $targetStatus = 'active';
        if ($stay->check_out_at !== null) {
            $targetStatus = 'finished';
        } elseif ($contractCheckOutDate->equalTo($operationalDate->copy()->startOfDay())) {
            $targetStatus = 'pending_checkout';
        }

        if ((string) ($stay->status ?? '') === $targetStatus) {
            return false;
        }

        if ($persist) {
            $stay->status = $targetStatus;
            $stay->save();
        }

        return true;
    }

    private function calculateContractualStayNightsTotal(Reservation $reservation): float
    {
        $total = 0.0;

        foreach ($reservation->reservationRooms as $reservationRoom) {
            $roomId = (int) ($reservationRoom->room_id ?? 0);
            $checkInDate = $this->safeParseDate($reservationRoom->check_in_date ?? null);
            $checkOutDate = $this->safeParseDate($reservationRoom->check_out_date ?? null);

            if (
                $roomId <= 0
                || !$checkInDate
                || !$checkOutDate
                || !$checkOutDate->gt($checkInDate)
            ) {
                continue;
            }

            $rows = StayNight::query()
                ->where('reservation_id', $reservation->id)
                ->where('room_id', $roomId)
                ->whereDate('date', '>=', $checkInDate->toDateString())
                ->whereDate('date', '<', $checkOutDate->toDateString())
                ->orderByDesc('is_paid')
                ->orderBy('id')
                ->get()
                ->groupBy(fn (StayNight $night): string => $this->dateKey($night->date));

            foreach ($rows as $group) {
                /** @var \App\Models\StayNight|null $night */
                $night = $group->first();
                if (!$night) {
                    continue;
                }
                $total += (float) ($night->price ?? 0);
            }
        }

        return round($total, 2);
    }

    /**
     * @return array{paid_flags_updated:int,paid_nights:int,total_nights:int,remaining_amount:float}
     */
    private function rebuildStayNightPaidStateFromPayments(Reservation $reservation, bool $persist): array
    {
        $remainingCents = (int) round(max(0, (float) Payment::query()
            ->where('reservation_id', $reservation->id)
            ->sum('amount')) * 100);
        $contractCovered = $this->isContractualLodgingCoveredByNetPayments($reservation);

        $queue = $this->buildPaymentQueue($reservation);
        $paidFlagsUpdated = 0;
        $paidNights = 0;
        $totalNights = count($queue);

        foreach ($queue as $entry) {
            /** @var \App\Models\StayNight $night */
            $night = $entry['night'];
            $shareCents = (int) ($entry['share_cents'] ?? 0);

            $shouldBePaid = $contractCovered || $shareCents <= 0 || $remainingCents >= $shareCents;
            if ($shouldBePaid && $shareCents > 0) {
                $remainingCents = max(0, $remainingCents - $shareCents);
            }

            if ((bool) $night->is_paid !== $shouldBePaid) {
                $paidFlagsUpdated++;
                if ($persist) {
                    $night->is_paid = $shouldBePaid;
                    $night->save();
                }
            }

            if ($shouldBePaid) {
                $paidNights++;
            }
        }

        return [
            'paid_flags_updated' => $paidFlagsUpdated,
            'paid_nights' => $paidNights,
            'total_nights' => $totalNights,
            'remaining_amount' => round($remainingCents / 100, 2),
        ];
    }

    private function isContractualLodgingCoveredByNetPayments(Reservation $reservation): bool
    {
        $contractualTotal = $this->resolveContractualLodgingTotal($reservation);
        if ($contractualTotal <= 0) {
            return false;
        }

        $paymentsNet = round(max(0, (float) Payment::query()
            ->where('reservation_id', $reservation->id)
            ->sum('amount')), 2);

        return $paymentsNet + 0.01 >= $contractualTotal;
    }

    private function resolveContractualLodgingTotal(Reservation $reservation): float
    {
        $reservation->loadMissing(['reservationRooms']);

        $rooms = $reservation->reservationRooms ?? collect();
        if ($rooms->isNotEmpty()) {
            $roomsWithSubtotal = $rooms->filter(static fn ($room) => (float) ($room->subtotal ?? 0) > 0);
            if ($roomsWithSubtotal->count() === $rooms->count()) {
                return round((float) $roomsWithSubtotal->sum(static fn ($room) => (float) ($room->subtotal ?? 0)), 2);
            }
        }

        $reservationTotal = round((float) ($reservation->total_amount ?? 0), 2);
        if ($reservationTotal > 0) {
            return $reservationTotal;
        }

        return $this->calculateContractualStayNightsTotal($reservation);
    }

    /**
     * @return array<int,array{night:\App\Models\StayNight,share_cents:int,date:string,room_id:int,id:int}>
     */
    private function buildPaymentQueue(Reservation $reservation): array
    {
        $entries = [];

        foreach ($reservation->reservationRooms as $reservationRoom) {
            $roomId = (int) ($reservationRoom->room_id ?? 0);
            $checkInDate = $this->safeParseDate($reservationRoom->check_in_date ?? null);
            $checkOutDate = $this->safeParseDate($reservationRoom->check_out_date ?? null);

            if (
                $roomId <= 0
                || !$checkInDate
                || !$checkOutDate
                || !$checkOutDate->gt($checkInDate)
            ) {
                continue;
            }

            $rows = StayNight::query()
                ->where('reservation_id', $reservation->id)
                ->where('room_id', $roomId)
                ->whereDate('date', '>=', $checkInDate->toDateString())
                ->whereDate('date', '<', $checkOutDate->toDateString())
                ->orderByDesc('is_paid')
                ->orderBy('id')
                ->get()
                ->groupBy(fn (StayNight $night): string => $this->dateKey($night->date));

            foreach ($rows as $date => $group) {
                /** @var \App\Models\StayNight|null $night */
                $night = $group->first();
                if (!$night) {
                    continue;
                }

                $entries[] = [
                    'night' => $night,
                    'share_cents' => (int) round(max(0, (float) ($night->price ?? 0)) * 100),
                    'date' => $date,
                    'room_id' => $roomId,
                    'id' => (int) $night->id,
                ];
            }
        }

        usort($entries, function (array $a, array $b): int {
            $dateCompare = strcmp((string) $a['date'], (string) $b['date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            if ((int) $a['room_id'] !== (int) $b['room_id']) {
                return (int) $a['room_id'] <=> (int) $b['room_id'];
            }

            return (int) $a['id'] <=> (int) $b['id'];
        });

        return $entries;
    }

    /**
     * @return array{updated:bool,payments_total:float,sales_debt:float,balance_due:float}
     */
    private function syncReservationFinancialColumns(
        Reservation $reservation,
        float $lodgingTotal,
        bool $keepTotal,
        bool $withoutSalesDebt,
        bool $persist
    ): array {
        $paymentsTotal = round((float) Payment::query()
            ->where('reservation_id', $reservation->id)
            ->sum('amount'), 2);

        $salesDebt = 0.0;
        if (!$withoutSalesDebt) {
            $salesDebt = round((float) $reservation->sales()
                ->where('is_paid', false)
                ->sum('total'), 2);
        }

        $balanceDue = round(max(0, $lodgingTotal - $paymentsTotal + $salesDebt), 2);

        $paymentStatusCode = $balanceDue <= 0
            ? 'paid'
            : ($paymentsTotal > 0 ? 'partial' : 'pending');
        $paymentStatusId = $this->resolvePaymentStatusId($paymentStatusCode);

        $updates = [];
        if (!$keepTotal && abs((float) ($reservation->total_amount ?? 0) - $lodgingTotal) > 0.009) {
            $updates['total_amount'] = $lodgingTotal;
        }

        $depositAmount = max(0, $paymentsTotal);
        if (abs((float) ($reservation->deposit_amount ?? 0) - $depositAmount) > 0.009) {
            $updates['deposit_amount'] = $depositAmount;
        }

        if (abs((float) ($reservation->balance_due ?? 0) - $balanceDue) > 0.009) {
            $updates['balance_due'] = $balanceDue;
        }

        if ($paymentStatusId !== null && (int) ($reservation->payment_status_id ?? 0) !== $paymentStatusId) {
            $updates['payment_status_id'] = $paymentStatusId;
        }

        if ($persist && !empty($updates)) {
            $reservation->forceFill($updates);
            $reservation->save();
        }

        return [
            'updated' => !empty($updates),
            'payments_total' => $paymentsTotal,
            'sales_debt' => $salesDebt,
            'balance_due' => $balanceDue,
        ];
    }

    private function resolvePaymentStatusId(string $code): ?int
    {
        if (array_key_exists($code, $this->paymentStatusIds)) {
            return $this->paymentStatusIds[$code];
        }

        $id = DB::table('payment_statuses')
            ->where('code', $code)
            ->value('id');

        $this->paymentStatusIds[$code] = $id !== null ? (int) $id : null;

        return $this->paymentStatusIds[$code];
    }

    /**
     * @return array<string,bool>
     */
    private function buildTargetDateMap(Carbon $checkInDate, Carbon $checkOutDate): array
    {
        $map = [];
        for ($cursor = $checkInDate->copy(); $cursor->lt($checkOutDate); $cursor->addDay()) {
            $map[$cursor->toDateString()] = true;
        }

        return $map;
    }

    private function resolveNightPrice(
        Reservation $reservation,
        ReservationRoom $reservationRoom,
        Carbon $checkInDate,
        Carbon $checkOutDate
    ): float {
        $nightsFromRange = max(1, $checkInDate->diffInDays($checkOutDate));
        $subtotal = round((float) ($reservationRoom->subtotal ?? 0), 2);
        if ($subtotal > 0 && $nightsFromRange > 0) {
            return round($subtotal / $nightsFromRange, 2);
        }

        // Contingencia legacy:
        // en reservas de una sola habitacion, priorizar total_amount para evitar
        // arrastrar price_per_night inflado por calculos historicos incorrectos.
        if ($reservation->reservationRooms->count() === 1) {
            $reservationTotal = round((float) ($reservation->total_amount ?? 0), 2);
            if ($reservationTotal > 0 && $nightsFromRange > 0) {
                return round($reservationTotal / $nightsFromRange, 2);
            }
        }

        $pricePerNight = round((float) ($reservationRoom->price_per_night ?? 0), 2);
        if ($pricePerNight > 0) {
            return $pricePerNight;
        }

        $existingPrice = (float) StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->where('room_id', (int) ($reservationRoom->room_id ?? 0))
            ->orderByDesc('date')
            ->value('price');
        if ($existingPrice > 0) {
            return round($existingPrice, 2);
        }

        $totalNights = $this->totalContractualNights($reservation->reservationRooms);
        if ($totalNights > 0) {
            $reservationTotal = round((float) ($reservation->total_amount ?? 0), 2);
            if ($reservationTotal > 0) {
                return round($reservationTotal / $totalNights, 2);
            }
        }

        return 0.0;
    }

    private function totalContractualNights(Collection $reservationRooms): int
    {
        $total = 0;
        foreach ($reservationRooms as $reservationRoom) {
            $checkInDate = $this->safeParseDate($reservationRoom->check_in_date ?? null);
            $checkOutDate = $this->safeParseDate($reservationRoom->check_out_date ?? null);
            if (!$checkInDate || !$checkOutDate || !$checkOutDate->gt($checkInDate)) {
                continue;
            }
            $total += $checkInDate->diffInDays($checkOutDate);
        }

        return $total;
    }

    private function pickCanonicalNight(Collection $nightsForDate, ?int $preferredStayId): StayNight
    {
        if ($preferredStayId !== null) {
            $preferred = $nightsForDate->first(
                fn (StayNight $night): bool => (int) ($night->stay_id ?? 0) === $preferredStayId
            );
            if ($preferred) {
                return $preferred;
            }
        }

        $paid = $nightsForDate->first(fn (StayNight $night): bool => (bool) $night->is_paid);
        if ($paid) {
            return $paid;
        }

        /** @var \App\Models\StayNight $first */
        $first = $nightsForDate->first();

        return $first;
    }

    private function safeParseDate(mixed $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value, HotelTime::timezone())->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateKey(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        try {
            return Carbon::parse((string) $value, HotelTime::timezone())->toDateString();
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
