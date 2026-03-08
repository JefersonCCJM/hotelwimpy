@props(['rooms', 'currentDate'])

<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-[1360px] w-full divide-y divide-gray-100" style="position: static;">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Habitacion</th>
                    <th class="px-6 py-4 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Estado</th>
                    <th class="px-6 py-4 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Estado de Limpieza</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Observacion diaria</th>
                    <th class="px-6 py-4 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Ventilacion</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-[280px]">Huesped Actual / Info</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Cuenta</th>
                    <th class="px-6 py-4 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200" style="position: static;">
                @forelse($rooms as $room)
                    <x-room-manager.room-row :room="$room" :currentDate="$currentDate" wire:key="room-row-{{ $room->id }}-{{ $currentDate instanceof \Carbon\Carbon ? $currentDate->format('Y-m-d') : \Carbon\Carbon::parse($currentDate)->format('Y-m-d') }}" />
                @empty
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center">
                                <i class="fas fa-door-closed text-4xl text-gray-300 mb-4"></i>
                                <p class="text-base font-semibold text-gray-500 mb-1">No se encontraron habitaciones</p>
                                <p class="text-sm text-gray-400">Registra tu primera habitacion para comenzar</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="bg-white px-6 py-4 border-t border-gray-100">
        {{ $rooms->links() }}
    </div>
</div>
