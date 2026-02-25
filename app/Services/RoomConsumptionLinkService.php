<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\ReservationSale;
use App\Models\Room;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class RoomConsumptionLinkService
{
    public function resolveReservationForRoomOnDate(int $roomId, Carbon $saleDate): ?Reservation
    {
        $room = Room::query()->find($roomId);
        if (!$room) {
            return null;
        }

        $operationalDate = $saleDate->copy()->startOfDay();
        $stay = $room->getAvailabilityService()->getStayForDate($operationalDate);
        if ($stay?->reservation) {
            $hasOperationalCoverage = $stay->reservation
                ->reservationRooms()
                ->where('room_id', $roomId)
                ->whereDate('check_in_date', '<=', $operationalDate->toDateString())
                ->whereDate('check_out_date', '>', $operationalDate->toDateString())
                ->exists();

            if ($hasOperationalCoverage) {
                return $stay->reservation;
            }
        }

        $reservationRoom = ReservationRoom::query()
            ->with('reservation')
            ->where('room_id', $roomId)
            ->whereDate('check_in_date', '<=', $operationalDate->toDateString())
            ->whereDate('check_out_date', '>', $operationalDate->toDateString())
            ->whereHas('reservation', static function ($query): void {
                $query->whereNull('deleted_at');
            })
            ->orderByDesc('check_in_date')
            ->first();

        return $reservationRoom?->reservation;
    }

    public function syncSaleItemsToReservation(Sale $sale, ?Reservation $reservation = null): void
    {
        if (empty($sale->room_id)) {
            return;
        }

        $reservation = $reservation ?? $this->resolveReservationForSale($sale);
        if (!$reservation) {
            throw new \RuntimeException('La habitación no tiene una reserva activa para cargar el consumo.');
        }

        $sale->loadMissing('items');

        $isPaid = $this->isSalePaid($sale);
        $paymentMethod = $isPaid ? (string) ($sale->payment_method ?? 'efectivo') : 'pendiente';

        foreach ($sale->items as $item) {
            $payload = [
                'reservation_id' => (int) $reservation->id,
                'product_id' => (int) $item->product_id,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) ($item->unit_price ?? 0),
                'total' => (float) ($item->total ?? 0),
                'payment_method' => $paymentMethod,
                'is_paid' => $isPaid,
            ];

            if ($this->supportsSaleLinks()) {
                ReservationSale::query()->updateOrCreate(
                    ['sale_item_id' => (int) $item->id],
                    array_merge($payload, [
                        'sale_id' => (int) $sale->id,
                        'sale_item_id' => (int) $item->id,
                    ])
                );

                continue;
            }

            ReservationSale::query()->create($payload);
        }
    }

    public function syncPaymentStatusFromSale(Sale $sale): void
    {
        if (empty($sale->room_id) || !$this->supportsSaleLinks()) {
            return;
        }

        $isPaid = $this->isSalePaid($sale);
        $paymentMethod = $isPaid ? (string) ($sale->payment_method ?? 'efectivo') : 'pendiente';
        $saleItemIds = $sale->items()
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        ReservationSale::query()
            ->where(function ($query) use ($sale, $saleItemIds): void {
                $query->where('sale_id', (int) $sale->id);

                if (!empty($saleItemIds)) {
                    $query->orWhereIn('sale_item_id', $saleItemIds);
                }
            })
            ->update([
                'payment_method' => $paymentMethod,
                'is_paid' => $isPaid,
            ]);
    }

    public function deleteLinkedReservationSales(Sale $sale): void
    {
        if (empty($sale->room_id) || !$this->supportsSaleLinks()) {
            return;
        }

        $saleItemIds = $sale->items()
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        ReservationSale::query()
            ->where(function ($query) use ($sale, $saleItemIds): void {
                $query->where('sale_id', (int) $sale->id);

                if (!empty($saleItemIds)) {
                    $query->orWhereIn('sale_item_id', $saleItemIds);
                }
            })
            ->delete();
    }

    private function resolveReservationForSale(Sale $sale): ?Reservation
    {
        $roomId = (int) ($sale->room_id ?? 0);
        if ($roomId <= 0) {
            return null;
        }

        $saleDate = $sale->sale_date instanceof Carbon
            ? $sale->sale_date->copy()
            : Carbon::parse((string) $sale->sale_date);

        return $this->resolveReservationForRoomOnDate($roomId, $saleDate);
    }

    private function isSalePaid(Sale $sale): bool
    {
        if ((string) ($sale->debt_status ?? '') === 'pendiente') {
            return false;
        }

        return (string) ($sale->payment_method ?? '') !== 'pendiente';
    }

    private function supportsSaleLinks(): bool
    {
        return Schema::hasColumn('reservation_sales', 'sale_id')
            && Schema::hasColumn('reservation_sales', 'sale_item_id');
    }
}
