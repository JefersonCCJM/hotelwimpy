<?php

namespace App\Livewire\RoomManager;

use App\Models\Room;
use App\Services\RoomCleaningStatusMutationService;
use App\Services\RoomManagerGridHydrationService;
use App\Support\HotelTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class RoomRow extends Component
{
    public Room $room;
    public $currentDate = null;
    public int $roomId;
    public bool $hasVisibilityFilters = false;

    public function mount(Room $room, $currentDate, bool $hasVisibilityFilters = false): void
    {
        $this->room = $room;
        $this->roomId = (int) $room->id;
        $this->hasVisibilityFilters = $hasVisibilityFilters;
        $this->currentDate = $currentDate instanceof Carbon
            ? $currentDate->copy()->startOfDay()
            : Carbon::parse((string) $currentDate)->startOfDay();
    }

    #[On('refresh-room-row')]
    public function refreshRoomRow($roomId): void
    {
        if ((int) $roomId !== $this->roomId) {
            return;
        }

        $refreshedRoom = app(RoomManagerGridHydrationService::class)
            ->loadRoomForGrid($this->roomId, $this->selectedDate());

        if ($refreshedRoom) {
            $this->room = $refreshedRoom;
        }
    }

    public function updateCleaningStatus(string $status): void
    {
        $selectedDate = $this->selectedDate();
        if (HotelTime::isOperationalPastDate($selectedDate)) {
            $this->dispatch('notify', type: 'error', message: 'No se pueden hacer cambios en fechas históricas.');
            return;
        }

        $room = Room::find($this->roomId);
        if (!$room) {
            $this->dispatch('notify', type: 'error', message: 'Habitación no encontrada.');
            return;
        }

        $result = app(RoomCleaningStatusMutationService::class)
            ->apply($room, $selectedDate, $status, Auth::id());

        if (!($result['success'] ?? false)) {
            $this->dispatch('notify', type: 'error', message: (string) ($result['message'] ?? 'No fue posible actualizar el estado de limpieza.'));
            return;
        }

        $this->dispatch('notify', type: 'success', message: (string) ($result['message'] ?? 'Estado de limpieza actualizado.'));

        if (!empty($result['dispatch_clean_event'])) {
            $this->dispatch('room-marked-clean', roomId: $this->roomId);
        }

        if ($this->hasVisibilityFilters) {
            $this->dispatch('refreshRooms');
            return;
        }

        $this->reloadRoom();
    }

    public function render()
    {
        return view('livewire.room-manager.room-row');
    }

    private function reloadRoom(): void
    {
        $refreshedRoom = app(RoomManagerGridHydrationService::class)
            ->loadRoomForGrid($this->roomId, $this->selectedDate());

        if ($refreshedRoom) {
            $this->room = $refreshedRoom;
        }
    }

    private function selectedDate(): Carbon
    {
        return $this->currentDate instanceof Carbon
            ? $this->currentDate->copy()->startOfDay()
            : Carbon::parse((string) $this->currentDate)->startOfDay();
    }
}
