<?php

namespace App\Services;

use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomQuickReservation;
use App\Models\Stay;
use App\Support\HotelTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RoomCleaningStatusMutationService
{
    private array $reservationStatusCache = [];

    public function apply(Room $room, Carbon $selectedDate, string $status, ?int $userId = null): array
    {
        $selectedDate = $selectedDate->copy()->startOfDay();

        if (!in_array($status, ['limpia', 'pendiente', 'mantenimiento'], true)) {
            return ['success' => false, 'message' => 'Estado de limpieza inválido.', 'dispatch_clean_event' => false];
        }

        $operationalStatusService = app(RoomOperationalStatusService::class);
        $currentCleaningCode = data_get($room->cleaningStatus($selectedDate), 'code');
        $hasDailyMaintenanceOverride = $room->hasOperationalMaintenanceOverrideOn($selectedDate);

        if ($status !== 'mantenimiento' && $currentCleaningCode === 'mantenimiento' && !$hasDailyMaintenanceOverride) {
            return [
                'success' => false,
                'message' => 'Esta habitacion tiene un bloqueo de mantenimiento activo que no se puede cerrar desde este menu.',
                'dispatch_clean_event' => false,
            ];
        }

        if ($status === 'mantenimiento') {
            $validation = $this->canApplyMaintenanceToRoom($room, $selectedDate);
            if (!($validation['valid'] ?? false)) {
                return [
                    'success' => false,
                    'message' => (string) ($validation['message'] ?? 'No fue posible poner la habitacion en mantenimiento.'),
                    'dispatch_clean_event' => false,
                ];
            }

            $operationalStatusService->markMaintenance($room, $selectedDate, $userId);

            return [
                'success' => true,
                'message' => 'Habitacion marcada en mantenimiento para el dia operativo y el siguiente.',
                'dispatch_clean_event' => false,
            ];
        }

        if ($hasDailyMaintenanceOverride) {
            $operationalStatusService->clearMaintenance($room, $selectedDate, $userId);
        }

        if ($status === 'limpia') {
            $room->last_cleaned_at = now();
            $room->save();

            return [
                'success' => true,
                'message' => 'Habitación marcada como limpia.',
                'dispatch_clean_event' => true,
            ];
        }

        $room->last_cleaned_at = null;
        $room->save();

        return [
            'success' => true,
            'message' => 'Habitación marcada como pendiente de limpieza.',
            'dispatch_clean_event' => false,
        ];
    }

    private function canApplyMaintenanceToRoom(Room $room, Carbon $selectedDate): array
    {
        $selectedDate = $selectedDate->copy()->startOfDay();
        $currentCleaningCode = data_get($room->cleaningStatus($selectedDate), 'code');
        $baseCleaningCode = data_get($room->baseCleaningStatus($selectedDate), 'code');
        $operationalStatus = $room->getOperationalStatus($selectedDate);

        if ($operationalStatus !== 'free_clean' || $baseCleaningCode !== 'limpia') {
            return [
                'valid' => false,
                'message' => 'Solo se puede poner en mantenimiento una habitacion libre y limpia.',
            ];
        }

        $conflictStart = $currentCleaningCode === 'mantenimiento'
            ? $selectedDate->copy()->addDay()
            : $selectedDate->copy();
        $conflictEnd = $selectedDate->copy()->addDays(2);

        for ($dateToCheck = $conflictStart->copy(); $dateToCheck->lt($conflictEnd); $dateToCheck->addDay()) {
            if ($this->roomHasQuickReservationMarker((int) $room->id, $dateToCheck)) {
                return [
                    'valid' => false,
                    'message' => 'No se puede poner en mantenimiento porque la habitacion ya esta marcada como reservada en el rango afectado.',
                ];
            }

            if (
                $this->roomHasStayConflictOnOperationalDate($room, $dateToCheck)
                || $this->roomHasReservationConflictOnOperationalDate($room, $dateToCheck)
                || $room->isInMaintenance($dateToCheck)
            ) {
                return [
                    'valid' => false,
                    'message' => 'No se puede poner en mantenimiento porque la habitacion ya tiene reserva o bloqueo en el rango afectado.',
                ];
            }
        }

        return ['valid' => true, 'message' => null];
    }

    private function roomHasQuickReservationMarker(int $roomId, Carbon $date): bool
    {
        return RoomQuickReservation::query()
            ->where('room_id', $roomId)
            ->whereDate('operational_date', $date->toDateString())
            ->exists();
    }

    private function roomHasStayConflictOnOperationalDate(Room $room, Carbon $date): bool
    {
        $operationalDate = $date->copy()->startOfDay();
        $operationalStart = HotelTime::startOfOperationalDay($operationalDate);
        $operationalEnd = HotelTime::endOfOperationalDay($operationalDate);

        return Stay::query()
            ->where('room_id', (int) $room->id)
            ->whereIn('status', ['active', 'pending_checkout'])
            ->where('check_in_at', '<=', $operationalEnd)
            ->where(function ($query) use ($operationalStart) {
                $query->whereNull('check_out_at')
                    ->orWhere('check_out_at', '>=', $operationalStart);
            })
            ->exists();
    }

    private function roomHasReservationConflictOnOperationalDate(Room $room, Carbon $date): bool
    {
        $query = ReservationRoom::query()
            ->where('room_id', (int) $room->id)
            ->whereDate('check_in_date', '<=', $date->toDateString())
            ->whereDate('check_out_date', '>', $date->toDateString())
            ->whereHas('reservation', function ($query) {
                $query->whereNull('deleted_at');
            })
            ->whereDoesntHave('reservation.stays', function ($stayQuery) use ($room) {
                $stayQuery->where('room_id', (int) $room->id)
                    ->whereNotNull('check_in_at');
            });

        if (Schema::hasTable('reservation_statuses') && Schema::hasColumn('reservations', 'status_id')) {
            $excludedStatusIds = array_values(array_filter([
                $this->resolveReservationStatusId('checked_out'),
                $this->resolveReservationStatusId('cancelled'),
            ]));

            if ($excludedStatusIds !== []) {
                $query->whereHas('reservation', function ($reservationQuery) use ($excludedStatusIds) {
                    $reservationQuery
                        ->whereNull('deleted_at')
                        ->whereNotIn('status_id', $excludedStatusIds);
                });
            }
        }

        return $query->exists();
    }

    private function resolveReservationStatusId(string $type): ?int
    {
        if (array_key_exists($type, $this->reservationStatusCache)) {
            return $this->reservationStatusCache[$type];
        }

        if (!Schema::hasTable('reservation_statuses')) {
            return $this->reservationStatusCache[$type] = null;
        }

        $normalize = static function (?string $value): string {
            $raw = trim((string) ($value ?? ''));
            if ($raw === '') {
                return '';
            }

            $normalized = Str::ascii(strtolower($raw));
            $normalized = str_replace(['-', ' '], '_', $normalized);

            return preg_replace('/_+/', '_', $normalized) ?? '';
        };

        $matches = [
            'checked_out' => ['checked_out', 'check_out', 'checkedout', 'departed', 'salida', 'egresado', 'salio'],
            'cancelled' => ['cancelled', 'canceled', 'cancelada', 'cancelado', 'anulada', 'anulado'],
        ];

        $statuses = DB::table('reservation_statuses')
            ->select(['id', 'code', 'name'])
            ->get();

        $match = $statuses->first(function ($status) use ($normalize, $type, $matches): bool {
            $code = $normalize((string) ($status->code ?? ''));
            $name = $normalize((string) ($status->name ?? ''));

            if (in_array($code, $matches[$type] ?? [], true) || in_array($name, $matches[$type] ?? [], true)) {
                return true;
            }

            return match ($type) {
                'checked_out' => (str_contains($code, 'check') && str_contains($code, 'out'))
                    || (str_contains($name, 'check') && str_contains($name, 'out'))
                    || str_contains($code, 'salid')
                    || str_contains($name, 'salid')
                    || str_contains($code, 'egres')
                    || str_contains($name, 'egres'),
                'cancelled' => str_contains($code, 'cancel')
                    || str_contains($name, 'cancel')
                    || str_contains($code, 'anulad')
                    || str_contains($name, 'anulad'),
                default => false,
            };
        });

        return $this->reservationStatusCache[$type] = $match ? (int) $match->id : null;
    }
}
