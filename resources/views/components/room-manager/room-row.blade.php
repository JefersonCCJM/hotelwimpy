@props(['room', 'currentDate'])

@php
    use App\Enums\RoomDisplayStatus;
    use App\Support\HotelTime;
    use Carbon\Carbon;
    
    $selectedDate = $currentDate instanceof \Carbon\Carbon ? $currentDate : \Carbon\Carbon::parse($currentDate);
    $isPastDate = HotelTime::isOperationalPastDate($selectedDate);
    
    // SINGLE SOURCE OF TRUTH: Usar solo getOperationalStatus()
    // Para fechas pasadas, este metodo retorna el estado historico (inmutable)
    // Valores permitidos: 'occupied', 'pending_checkout', 'pending_cleaning', 'free_clean'
    $operationalStatus = $room->getOperationalStatus($selectedDate);
    $displayStatus = $room->display_status instanceof RoomDisplayStatus
        ? $room->display_status
        : $room->getDisplayStatus($selectedDate);
    $rowOperationalStatus = $displayStatus === RoomDisplayStatus::MANTENIMIENTO
        ? 'maintenance'
        : $operationalStatus;
    
    // SINGLE SOURCE OF TRUTH: Obtener stay UNA SOLA VEZ para la fecha seleccionada
    // CRITICAL: Solo usar stay para mostrar info de huesped/cuenta cuando operationalStatus es 'occupied' o 'pending_checkout'
    // Si operationalStatus es 'pending_cleaning' o 'free_clean', NO hay stay activa para mostrar
    $stay = null;
    if (in_array($operationalStatus, ['occupied', 'pending_checkout'])) {
        $stay = $room->getAvailabilityService()->getStayForDate($selectedDate);
        
        // Eager loading de relaciones necesarias para evitar N+1 queries
        if ($stay) {
            $stay->loadMissing([
                'reservation.customer',
                'reservation.reservationRooms' => function ($query) use ($room) {
                    $query->where('room_id', $room->id);
                }
            ]);
        }
    }

    $isQuickReserved = (bool) ($room->is_quick_reserved ?? false);
    $pendingCheckinReservation = $room->pending_checkin_reservation ?? $room->future_reservation ?? null;
    $pendingCheckinReservationCode = strtoupper(trim((string) ($pendingCheckinReservation->reservation_code ?? '')));
    $isPendingReservation = $pendingCheckinReservation && str_starts_with($pendingCheckinReservationCode, 'RES-');
    $hasStayInfo = $stay && $stay->reservation;
    $reservationBadge = !$hasStayInfo && $isPendingReservation ? $pendingCheckinReservation : null;
    $reservationBadgeCode = strtoupper(trim((string) ($reservationBadge->reservation_code ?? '')));
    $hasReservationBadge = str_starts_with($reservationBadgeCode, 'RES-');
@endphp

<tr
    x-data="{ 
        isReleasing: false,
        recentlyReleased: false,
        // Estado inicial desde BD (Single Source of Truth)
        // Valores permitidos: 'occupied', 'pending_checkout', 'pending_cleaning', 'free_clean'
        operationalStatus: '{{ $rowOperationalStatus }}',
        quickReserved: @js($isQuickReserved),
        isPastDate: @js($isPastDate),
        // Computed: Determina el estado visual a mostrar
        get displayState() {
            if (this.isReleasing) return 'releasing';
            if (this.recentlyReleased) return 'released';
            if (this.quickReserved && this.operationalStatus === 'free_clean') return 'quick_reserved';
            return this.operationalStatus;
        },
    }"
    x-init="
        // PERFORMANCE: Listener para cambio de vista - Resetear estados Alpine inmediatamente
        // Esto evita estados congelados y lag perceptible al cambiar de fecha
        window.addEventListener('room-view-changed', () => {
            // Resetear estados locales para evitar estados congelados del dia anterior
            this.isReleasing = false;
            this.recentlyReleased = false;
            // El operationalStatus se actualizara automaticamente cuando Livewire re-renderice
            // con el nuevo valor desde PHP (getOperationalStatus para la nueva fecha)
        });
        
        if (!this.isPastDate) {
            // Listener: Inicio de liberacion - Congela estado visual (solo para hoy/futuro)
            window.addEventListener('room-release-start', e => {
                if (e.detail?.roomId === {{ $room->id }}) {
                    this.isReleasing = true;
                    this.recentlyReleased = false;
                    // Congelar el estado operativo actual
                }
            });
            
            // Listener: Finalizacion de liberacion - Actualiza a pending_cleaning (solo para hoy/futuro)
            window.addEventListener('room-release-finished', e => {
                if (e.detail?.roomId === {{ $room->id }}) {
                    this.isReleasing = false;
                    this.recentlyReleased = true;
                    // Actualizar estado operativo a pending_cleaning (estado real despues del checkout)
                    this.operationalStatus = 'pending_cleaning';
                    // Mostrar confirmacion visual por 2 segundos
                    setTimeout(() => { 
                        this.recentlyReleased = false;
                        // El estado operativo ya esta sincronizado a 'pending_cleaning'
                        // y se sincronizara desde BD en el proximo render de Livewire
                    }, 2000);
                }
            });
            
            // Listener: Habitacion marcada como limpia - Actualiza a free_clean (solo para hoy/futuro)
            window.addEventListener('room-marked-clean', e => {
                if (e.detail?.roomId === {{ $room->id }}) {
                    this.operationalStatus = 'free_clean';
                    this.recentlyReleased = false;
                    this.isReleasing = false;
                }
            });

            window.addEventListener('room-quick-reserve-marked', e => {
                if (e.detail?.roomId === {{ $room->id }}) {
                    this.quickReserved = true;
                    this.recentlyReleased = false;
                    this.isReleasing = false;
                }
            });

            window.addEventListener('room-quick-reserve-cleared', e => {
                if (e.detail?.roomId === {{ $room->id }}) {
                    this.quickReserved = false;
                }
            });

            // Listener: Arriendo confirmado - fuerza sincronizacion inmediata de columnas
            window.addEventListener('room-rented', e => {
                if (e.detail?.roomId === {{ $room->id }}) {
                    this.operationalStatus = 'occupied';
                    this.quickReserved = false;
                    this.isReleasing = false;
                    this.recentlyReleased = false;
                }
            });
        }
        
        // Sincronizar estado despues de wire:poll o refreshRooms
        // Cuando Livewire re-renderiza, Alpine se reinicializa con el nuevo operationalStatus desde PHP
        // Para fechas pasadas, el estado siempre viene desde BD y es inmutable
    "
    class="transition-colors duration-150 group"
    :class="{
        'bg-emerald-50': displayState === 'released',
        'bg-blue-50/60': displayState === 'quick_reserved',
        'bg-amber-50/40': displayState === 'maintenance',
        'bg-red-50/40': displayState === 'occupied',
        'bg-orange-50/30': displayState === 'pending_checkout',
        'bg-yellow-50/30': displayState === 'pending_cleaning',
        'bg-emerald-50/30': displayState === 'free_clean'
    }"
    wire:key="room-{{ $room->id }}" style="position: static;">
    <td class="px-6 py-4 whitespace-nowrap align-top">
        <div class="flex items-center">
            <div class="h-10 w-10 rounded-lg bg-gray-100 flex items-center justify-center mr-3 text-gray-400 group-hover:bg-blue-50 group-hover:text-blue-600 transition-colors">
                <i class="fas fa-door-closed"></i>
            </div>
            <div wire:click="openRoomDetail({{ $room->id }})" class="cursor-pointer">
                <div class="text-sm font-semibold text-gray-900">Hab. {{ $room->room_number }}</div>
                <div class="text-xs text-gray-500">
                    {{ $room->beds_count }} {{ $room->beds_count == 1 ? 'Cama' : 'Camas' }} | Cap. {{ $room->max_capacity }}
                </div>
            </div>
        </div>
    </td>

    <td class="px-6 py-4 whitespace-nowrap text-center align-top">
        <div class="flex flex-col items-center gap-1.5">
            <span
                class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold transition-colors duration-300"
                :class="{
                    'bg-gray-100 text-gray-600': displayState === 'releasing',
                    'bg-emerald-100 text-emerald-700 border border-emerald-200': displayState === 'released',
                    'bg-blue-100 text-blue-700 border border-blue-200': displayState === 'quick_reserved',
                    'bg-amber-100 text-amber-700 border border-amber-200': displayState === 'maintenance',
                    'bg-red-100 text-red-700 border border-red-200': displayState === 'occupied',
                    'bg-orange-100 text-orange-700 border border-orange-200': displayState === 'pending_checkout',
                    'bg-yellow-100 text-yellow-700 border border-yellow-200': displayState === 'pending_cleaning',
                    'bg-emerald-100 text-emerald-700 border border-emerald-200': displayState === 'free_clean'
                }">
                <span class="w-1.5 h-1.5 rounded-full mr-2" :class="displayState === 'releasing' ? 'animate-spin' : ''" style="background-color: currentColor"></span>
                <template x-if="displayState === 'releasing'">
                    <span><i class="fas fa-spinner fa-spin mr-1"></i>Liberando...</span>
                </template>
                <template x-if="displayState === 'released'">
                    <span x-transition.opacity><i class="fas fa-check-circle mr-1"></i>Habitacion liberada</span>
                </template>
                <template x-if="displayState === 'quick_reserved'">
                    <span><i class="fas fa-bookmark mr-1"></i>Reservada</span>
                </template>
                <template x-if="displayState === 'maintenance'">
                    <span><i class="fas fa-screwdriver-wrench mr-1"></i>Mantenimiento</span>
                </template>
                <template x-if="displayState === 'occupied'">
                    <span>Ocupada</span>
                </template>
                <template x-if="displayState === 'pending_checkout'">
                    <span><i class="fas fa-door-open mr-1"></i>Pendiente por checkout</span>
                </template>
                <template x-if="displayState === 'pending_cleaning'">
                    {{-- CRITICAL: Estado operativo 'pending_cleaning' significa que la habitacion esta LIBRE pero necesita limpieza --}}
                    {{-- El estado de LIMPIEZA se muestra en su propia columna separada --}}
                    <span>Libre</span>
                </template>
                <template x-if="displayState === 'free_clean'">
                    <span>Libre</span>
                </template>
            </span>

            @if ($hasReservationBadge && $rowOperationalStatus !== 'maintenance')
                <div
                    class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-50 text-blue-600 border border-blue-200"
                    title="{{ $reservationBadge->customer->name ?? '' }}">
                    <i class="fas fa-calendar-check text-[8px]"></i>
                    <span>{{ \Illuminate\Support\Str::limit($reservationBadge->customer->name ?? 'Sin cliente', 15) }}</span>
                    <span class="font-mono text-blue-500">{{ $reservationBadgeCode }}</span>
                </div>
            @endif
        </div>
    </td>

    <td class="px-6 py-4 whitespace-nowrap text-center align-top">
        {{-- SINGLE SOURCE OF TRUTH: El estado de limpieza se calcula independientemente del estado operativo --}}
        <x-room-manager.room-cleaning-status :room="$room" :selectedDate="$selectedDate" />
    </td>

    <td class="px-6 py-4 align-top">
        <x-room-manager.room-daily-observation :room="$room" :selectedDate="$selectedDate" />
    </td>

    <td class="px-6 py-4 whitespace-nowrap text-center align-top">
        @if($room->ventilationType)
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700">
                <i class="fas fa-wind mr-1.5"></i>
                {{ $room->ventilationType->name }}
            </span>
        @else
            <span class="text-xs text-gray-400 italic">No asignado</span>
        @endif
    </td>

    <td class="px-6 py-4 align-top w-[280px] max-w-[280px]">
        @if($hasStayInfo)
            <x-room-manager.room-guest-info :room="$room" :stay="$stay" :selectedDate="$selectedDate" />
        @else
            @if($rowOperationalStatus === 'maintenance')
                <span class="text-xs font-semibold text-amber-700">Bloqueada por mantenimiento</span>
            @elseif($operationalStatus === 'pending_cleaning')
                <span class="text-xs text-gray-500 italic">Checkout realizado</span>
            @elseif($isQuickReserved && $operationalStatus === 'free_clean')
                <span class="text-xs text-blue-700 font-semibold">Reservada sin datos</span>
            @else
                <span class="text-xs text-gray-400 italic">Sin arrendatario</span>
            @endif
        @endif
    </td>

    <td class="px-6 py-4 align-top">
        @if($hasStayInfo)
            <x-room-manager.room-payment-info :room="$room" :stay="$stay" :selectedDate="$selectedDate" />
        @else
            @if($rowOperationalStatus === 'maintenance')
                <div class="flex flex-col">
                    <span class="text-xs font-semibold text-amber-700">No disponible</span>
                    <span class="text-[10px] text-amber-600">La habitacion esta en mantenimiento</span>
                </div>
            @elseif($operationalStatus === 'pending_cleaning')
                <div class="flex flex-col">
                    <span class="text-xs text-gray-500 font-semibold">Cuenta cerrada</span>
                    <span class="text-[10px] text-gray-400">Marcar como limpia para arrendar</span>
                </div>
            @elseif($isQuickReserved && $operationalStatus === 'free_clean')
                <div class="flex flex-col">
                    <span class="text-sm font-semibold text-gray-900">${{ number_format($room->base_price_per_night ?? 0, 0, ',', '.') }}</span>
                    <span class="text-xs text-blue-600">{{ $isPendingReservation ? 'reserva pendiente de check-in' : 'reserva rapida del dia' }}</span>
                </div>
            @else
                <div class="flex flex-col">
                    <span class="text-sm font-semibold text-gray-900">${{ number_format($room->base_price_per_night ?? 0, 0, ',', '.') }}</span>
                    <span class="text-xs text-gray-400">precio base</span>
                </div>
            @endif
        @endif
    </td>

    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium align-top" style="position: static;">
        @if($isPastDate)
            <span class="text-xs text-gray-400 italic">Historico</span>
        @else
            <x-room-manager.room-actions-menu :room="$room" :currentDate="$currentDate" />
        @endif
    </td>
</tr>
