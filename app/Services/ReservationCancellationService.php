<?php

namespace App\Services;

use App\Enums\ShiftHandoverStatus;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomQuickReservation;
use App\Models\RoomReleaseHistory;
use App\Models\ShiftHandover;
use App\Models\Stay;
use App\Models\StayNight;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReservationCancellationService
{
    /**
     * Cancela una reserva de forma operativa completa.
     *
     * Efectos principales:
     * - Revierte pagos positivos con asientos negativos (si no estaban revertidos)
     * - Elimina noches cobrables y estancias operativas de la reserva
     * - Limpia marcadores de reserva rapida relacionados
     * - Elimina historial de liberacion asociado
     * - Soft-delete de la reserva
     * - Marca como limpias las habitaciones que quedaron liberadas
     *
     * @return array{
     *     reservation_id:int,
     *     reversed_payments:int,
     *     deleted_stays:int,
     *     deleted_stay_nights:int,
     *     deleted_quick_reservations:int,
     *     deleted_release_history:int,
     *     rooms_marked_clean:int
     * }
     */
    public function cancelCompletely(Reservation $reservation, ?int $performedByUserId = null): array
    {
        return DB::transaction(function () use ($reservation, $performedByUserId): array {
            $performedByUserId = $performedByUserId ?: auth()->id();

            $reservation->loadMissing(['reservationRooms:id,reservation_id,room_id,check_in_date']);

            // Determinar habitaciones realmente ocupadas por esta reserva antes de borrar stays.
            $roomIdsWithOperationalStay = Stay::query()
                ->where('reservation_id', $reservation->id)
                ->whereIn('status', ['active', 'pending_checkout'])
                ->pluck('room_id')
                ->filter()
                ->map(static fn ($id) => (int) $id)
                ->unique()
                ->values();

            $reversedPayments = 0;
            $positivePayments = Payment::query()
                ->where('reservation_id', $reservation->id)
                ->where('amount', '>', 0)
                ->get(['id', 'payment_method_id', 'bank_name', 'amount']);

            foreach ($positivePayments as $payment) {
                $reversalReference = 'Anulacion de pago #' . (int) $payment->id;

                $alreadyReversed = Payment::query()
                    ->where('reservation_id', $reservation->id)
                    ->where('amount', '<', 0)
                    ->where('reference', $reversalReference)
                    ->exists();

                if ($alreadyReversed) {
                    continue;
                }

                Payment::create([
                    'reservation_id' => $reservation->id,
                    'amount' => -1 * abs((float) $payment->amount),
                    'payment_method_id' => $payment->payment_method_id,
                    'bank_name' => $payment->bank_name,
                    'reference' => $reversalReference,
                    'paid_at' => now(),
                    'created_by' => $performedByUserId,
                ]);

                $reversedPayments++;
            }

            $deletedStayNights = StayNight::query()
                ->where('reservation_id', $reservation->id)
                ->delete();

            $deletedStays = Stay::query()
                ->where('reservation_id', $reservation->id)
                ->delete();

            $deletedQuickReservations = 0;
            foreach ($reservation->reservationRooms as $reservationRoom) {
                $roomId = (int) ($reservationRoom->room_id ?? 0);
                $checkInDate = $reservationRoom->check_in_date;

                if ($roomId <= 0 || empty($checkInDate)) {
                    continue;
                }

                $deletedQuickReservations += RoomQuickReservation::query()
                    ->where('room_id', $roomId)
                    ->whereDate('operational_date', Carbon::parse((string) $checkInDate)->toDateString())
                    ->delete();
            }

            $deletedReleaseHistory = RoomReleaseHistory::query()
                ->where('reservation_id', $reservation->id)
                ->delete();

            $reservation->delete();

            $roomsMarkedClean = 0;
            if ($roomIdsWithOperationalStay->isNotEmpty()) {
                $roomsMarkedClean = Room::query()
                    ->whereIn('id', $roomIdsWithOperationalStay->all())
                    ->update(['last_cleaned_at' => now()]);
            }

            if ($reversedPayments > 0 && !empty($performedByUserId)) {
                $activeShift = ShiftHandover::query()
                    ->where('entregado_por', $performedByUserId)
                    ->where('status', ShiftHandoverStatus::ACTIVE)
                    ->first();

                if ($activeShift && $activeShift->started_at) {
                    $activeShift->updateTotals();
                }
            }

            return [
                'reservation_id' => (int) $reservation->id,
                'reversed_payments' => $reversedPayments,
                'deleted_stays' => (int) $deletedStays,
                'deleted_stay_nights' => (int) $deletedStayNights,
                'deleted_quick_reservations' => (int) $deletedQuickReservations,
                'deleted_release_history' => (int) $deletedReleaseHistory,
                'rooms_marked_clean' => (int) $roomsMarkedClean,
            ];
        });
    }
}

