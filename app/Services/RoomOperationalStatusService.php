<?php

namespace App\Services;

use App\Models\Room;
use App\Models\RoomOperationalStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RoomOperationalStatusService
{
    public function saveObservation(Room $room, Carbon $operationalDate, ?string $observation, ?int $userId = null): void
    {
        $operationalDate = $operationalDate->copy()->startOfDay();
        $normalizedObservation = trim((string) ($observation ?? ''));

        $status = $this->firstOrNew($room, $operationalDate);
        $status->observation = $normalizedObservation !== '' ? $normalizedObservation : null;

        $this->persistOrDelete($status, $userId);
    }

    public function markMaintenance(Room $room, Carbon $operationalDate, ?int $userId = null): void
    {
        $operationalDate = $operationalDate->copy()->startOfDay();
        $nextOperationalDate = $operationalDate->copy()->addDay();

        DB::transaction(function () use ($room, $operationalDate, $nextOperationalDate, $userId): void {
            $this->storeMaintenanceRecord($room, $operationalDate, $operationalDate, $userId);
            $this->storeMaintenanceRecord($room, $nextOperationalDate, $operationalDate, $userId);
        });
    }

    public function clearMaintenance(Room $room, Carbon $operationalDate, ?int $userId = null): void
    {
        $operationalDate = $operationalDate->copy()->startOfDay();
        $status = $this->firstForDate($room, $operationalDate);

        if (!$status || $status->cleaning_override_status !== 'mantenimiento') {
            return;
        }

        DB::transaction(function () use ($operationalDate, $status, $userId): void {
            $sourceDate = $status->maintenance_source_date?->copy()->startOfDay();

            if ($sourceDate && $sourceDate->equalTo($operationalDate)) {
                RoomOperationalStatus::query()
                    ->where('room_id', (int) $status->room_id)
                    ->whereDate('maintenance_source_date', $operationalDate->toDateString())
                    ->whereDate('operational_date', '>=', $operationalDate->toDateString())
                    ->orderBy('operational_date')
                    ->get()
                    ->each(function (RoomOperationalStatus $item) use ($userId): void {
                        $item->cleaning_override_status = null;
                        $item->maintenance_source_date = null;
                        $this->persistOrDelete($item, $userId);
                    });

                return;
            }

            $status->cleaning_override_status = null;
            $status->maintenance_source_date = null;
            $this->persistOrDelete($status, $userId);
        });
    }

    private function storeMaintenanceRecord(
        Room $room,
        Carbon $operationalDate,
        Carbon $sourceDate,
        ?int $userId = null
    ): void {
        $status = $this->firstOrNew($room, $operationalDate);
        $status->cleaning_override_status = 'mantenimiento';
        $status->maintenance_source_date = $sourceDate->copy()->startOfDay();

        $this->persistOrDelete($status, $userId);
    }

    private function firstOrNew(Room $room, Carbon $operationalDate): RoomOperationalStatus
    {
        $existing = RoomOperationalStatus::query()
            ->where('room_id', (int) $room->id)
            ->whereDate('operational_date', $operationalDate->toDateString())
            ->first();

        if ($existing) {
            return $existing;
        }

        return new RoomOperationalStatus([
            'room_id' => (int) $room->id,
            'operational_date' => $operationalDate->toDateString(),
        ]);
    }

    private function firstForDate(Room $room, Carbon $operationalDate): ?RoomOperationalStatus
    {
        return RoomOperationalStatus::query()
            ->where('room_id', (int) $room->id)
            ->whereDate('operational_date', $operationalDate->toDateString())
            ->first();
    }

    private function persistOrDelete(RoomOperationalStatus $status, ?int $userId = null): void
    {
        $hasPayload = $status->observation !== null || $status->cleaning_override_status !== null;

        if (!$hasPayload) {
            if ($status->exists) {
                $status->delete();
            }

            return;
        }

        if (!$status->exists) {
            $status->created_by = $status->created_by ?: $userId;
        }

        $status->updated_by = $userId;
        $status->save();
    }
}
