@props(['room', 'currentDate'])

@php
    $selectedDate = $currentDate instanceof \Carbon\Carbon ? $currentDate : \Carbon\Carbon::parse($currentDate);
    $isOperationalToday = \App\Support\HotelTime::isOperationalToday($selectedDate);
    $isFutureDate = \App\Support\HotelTime::isOperationalFutureDate($selectedDate);
    $isPastDate = \App\Support\HotelTime::isOperationalPastDate($selectedDate);

    // SINGLE SOURCE OF TRUTH: Estado operativo desde BD basado en stays y fecha seleccionada
    $operationalStatus = $room->getOperationalStatus($selectedDate);
    $cleaningCode = data_get($room->cleaningStatus($selectedDate), 'code');
    
    // CRITICAL: isPendingCheckout() solo retorna true para HOY
    // Nunca para fechas pasadas o futuras
    $isPendingCheckout = $room->isPendingCheckout($selectedDate);
    
    // Solo permitir acciones en fecha actual (no historicas ni futuras)
    $canPerformActions = !$isFutureDate && !$isPastDate;
    $canManageRooms = auth()->check() && auth()->user()->hasRole('Administrador');
    $isQuickReserved = (bool) ($room->is_quick_reserved ?? false);
    $hasPendingReservation = !empty($room->pending_checkin_reservation) || !empty($room->future_reservation);
@endphp

<div class="flex items-center justify-end gap-1.5">
    {{-- ESTADO: free_clean (Libre y limpia) --}}
    @if($operationalStatus === 'free_clean' && $cleaningCode === 'limpia')
        @if($isFutureDate)
            {{-- Cambiar habitacion de reserva futura pendiente --}}
            @if($room->future_reservation)
                <button type="button"
                    wire:click="openChangeRoom({{ $room->id }})"
                    wire:loading.attr="disabled"
                    title="Cambiar habitacion de reserva pendiente"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-purple-200 bg-purple-50 text-purple-600 hover:bg-purple-100 hover:border-purple-300 transition-colors focus:outline-none focus:ring-2 focus:ring-purple-500 disabled:opacity-50">
                    <i class="fas fa-exchange-alt text-sm"></i>
                    <span class="sr-only">Cambiar habitacion</span>
                </button>
            @endif
        @elseif($canPerformActions)
            {{-- Cambiar habitacion de reserva futura si tiene RES- --}}
            @if($hasPendingReservation)
                <button type="button"
                    wire:click="openChangeRoom({{ $room->id }})"
                    wire:loading.attr="disabled"
                    title="Cambiar habitacion de reserva pendiente"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-purple-200 bg-purple-50 text-purple-600 hover:bg-purple-100 hover:border-purple-300 transition-colors focus:outline-none focus:ring-2 focus:ring-purple-500 disabled:opacity-50">
                    <i class="fas fa-exchange-alt text-sm"></i>
                    <span class="sr-only">Cambiar habitacion</span>
                </button>
            @endif
            @if($hasPendingReservation)
                {{-- Check-in de reserva pendiente (HOY) --}}
                <button type="button"
                    wire:click="performReservationCheckIn({{ $room->id }})"
                    wire:loading.attr="disabled"
                    title="Realizar check-in"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 hover:border-emerald-300 transition-colors focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:opacity-50">
                    <i class="fas fa-door-open text-sm"></i>
                    <span class="sr-only">Realizar check-in</span>
                </button>
            @else
                {{-- Ocupar habitacion (HOY) --}}
                <button type="button"
                    wire:click="openQuickRent({{ $room->id }})"
                    wire:loading.attr="disabled"
                    title="Ocupar habitacion"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-blue-200 bg-blue-50 text-blue-600 hover:bg-blue-100 hover:border-blue-300 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50">
                    <i class="fas fa-key text-sm"></i>
                    <span class="sr-only">Ocupar habitacion</span>
                </button>
            @endif

        @endif

        @if(!$isPastDate)
            @if($isQuickReserved)
                <button type="button"
                    wire:click="cancelQuickReserve({{ $room->id }})"
                    wire:loading.attr="disabled"
                    title="{{ $hasPendingReservation ? 'Cancelar reserva' : 'Cancelar reserva rapida' }}"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-gray-300 bg-gray-100 text-gray-700 hover:bg-gray-200 hover:border-gray-400 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-500 disabled:opacity-50">
                    <i class="fas fa-times text-sm"></i>
                    <span class="sr-only">{{ $hasPendingReservation ? 'Cancelar reserva' : 'Cancelar reserva rapida' }}</span>
                </button>
            @else
                <button type="button"
                    wire:click="markRoomAsQuickReserved({{ $room->id }})"
                    wire:loading.attr="disabled"
                    title="Marcar como reservada"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-blue-300 bg-blue-100 text-blue-700 hover:bg-blue-200 hover:border-blue-400 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50">
                    <i class="fas fa-bookmark text-sm"></i>
                    <span class="sr-only">Reservar habitacion</span>
                </button>
            @endif
        @endif
    @endif

    {{-- ESTADO: occupied (Ocupada) - NO pendiente de checkout --}}
    @if($operationalStatus === 'occupied' && !$isPendingCheckout && $canPerformActions && $isOperationalToday)
        {{-- Cambiar habitacion --}}
        <button type="button"
            wire:click="openChangeRoom({{ $room->id }})"
            wire:loading.attr="disabled"
            title="Cambiar habitacion"
            class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-purple-200 bg-purple-50 text-purple-600 hover:bg-purple-100 hover:border-purple-300 transition-colors focus:outline-none focus:ring-2 focus:ring-purple-500 disabled:opacity-50">
            <i class="fas fa-exchange-alt text-sm"></i>
            <span class="sr-only">Cambiar habitacion</span>
        </button>
        {{-- Liberar: Solo si NO esta pendiente de checkout Y es HOY --}}
        <button type="button"
            @click="confirmRelease({{ $room->id }}, '{{ $room->room_number }}', 0, null, false);"
            title="Liberar"
            class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-yellow-200 bg-yellow-50 text-yellow-600 hover:bg-yellow-100 hover:border-yellow-300 transition-colors focus:outline-none focus:ring-2 focus:ring-yellow-500">
            <i class="fas fa-door-open text-sm"></i>
            <span class="sr-only">Liberar</span>
        </button>
    @endif

    {{-- ESTADO: pending_checkout (Pendiente por checkout) - SOLO PARA HOY --}}
    @if($operationalStatus === 'pending_checkout' && $canPerformActions && $isOperationalToday)
        {{-- Cambiar habitacion --}}
        <button type="button"
            wire:click="openChangeRoom({{ $room->id }})"
            wire:loading.attr="disabled"
            title="Cambiar habitacion"
            class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-purple-200 bg-purple-50 text-purple-600 hover:bg-purple-100 hover:border-purple-300 transition-colors focus:outline-none focus:ring-2 focus:ring-purple-500 disabled:opacity-50">
            <i class="fas fa-exchange-alt text-sm"></i>
            <span class="sr-only">Cambiar habitacion</span>
        </button>
        {{-- Continuar Estadia --}}
        <button type="button"
            wire:click="continueStay({{ $room->id }})"
            wire:loading.attr="disabled"
            title="Continuar estadia"
            class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 hover:border-emerald-300 transition-colors focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:opacity-50">
            <i class="fas fa-redo-alt text-sm"></i>
            <span class="sr-only">Continuar</span>
        </button>

        {{-- Cancelar Estadia --}}
        <button type="button"
            @click="confirmRelease({{ $room->id }}, '{{ $room->room_number }}', 0, null, false);"
            title="Cancelar estadia"
            class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-red-200 bg-red-50 text-red-700 hover:bg-red-100 hover:border-red-300 transition-colors focus:outline-none focus:ring-2 focus:ring-red-500 disabled:opacity-50">
            <i class="fas fa-times text-sm"></i>
            <span class="sr-only">Cancelar</span>
        </button>
    @endif

    {{-- ESTADO: pending_cleaning (Pendiente por aseo) --}}
    {{-- Anular ingreso: condicion independiente basada en operationalStatus (SSOT correcto) --}}
    {{-- cleaningCode puede retornar 'limpia' incorrectamente cuando el stay termino hoy --}}
    {{-- @if($operationalStatus === 'pending_cleaning' && $canPerformActions && $isOperationalToday)
        <button type="button"
            @click="confirmUndoCheckout({{ $room->id }}, '{{ addslashes($room->room_number) }}')"
            wire:loading.attr="disabled"
            title="Anular ingreso del dia"
            class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-orange-200 bg-orange-50 text-orange-600 hover:bg-orange-100 hover:border-orange-300 transition-colors focus:outline-none focus:ring-2 focus:ring-orange-500 disabled:opacity-50">
            <i class="fas fa-undo text-sm"></i>
            <span class="sr-only">Anular ingreso</span>
        </button>
    @endif --}}
    {{-- Marcar como limpia: solo si cleaningCode es pendiente --}}
    @if($cleaningCode === 'pendiente' && !in_array($operationalStatus, ['occupied', 'pending_checkout'], true) && $canPerformActions && $isOperationalToday)
        <button type="button"
            wire:click="markRoomAsClean({{ $room->id }})"
            wire:loading.attr="disabled"
            title="Marcar como limpia"
            class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-green-200 bg-green-50 text-green-600 hover:bg-green-100 hover:border-green-300 transition-colors focus:outline-none focus:ring-2 focus:ring-green-500 disabled:opacity-50">
            <i class="fas fa-broom text-sm"></i>
            <span class="sr-only">Marcar como limpia</span>
        </button>
    @endif

    {{-- SIEMPRE VISIBLES (excepto fecha pasada para editar) --}}
    
    {{-- Editar habitacion (no disponible en fechas pasadas ni con stays activos) --}}
    @if($canManageRooms && !$isPastDate && !in_array($operationalStatus, ['occupied', 'pending_checkout']))
        <button type="button"
            wire:click="openRoomEdit({{ $room->id }})"
            wire:loading.attr="disabled"
            title="Editar habitacion"
            class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-indigo-200 bg-indigo-50 text-indigo-600 hover:bg-indigo-100 hover:border-indigo-300 transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50">
            <i class="fas fa-edit text-sm"></i>
            <span class="sr-only">Editar habitacion</span>
        </button>
    @endif

    {{-- Eliminar habitacion (solo admin) --}}
    @if($canManageRooms && !$isPastDate && !in_array($operationalStatus, ['occupied', 'pending_checkout']))
        <button type="button"
            @click="confirmDeleteRoom({{ $room->id }}, @js($room->room_number))"
            wire:loading.attr="disabled"
            wire:target="deleteRoom"
            title="Eliminar habitacion"
            class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-red-200 bg-red-50 text-red-600 hover:bg-red-100 hover:border-red-300 transition-colors focus:outline-none focus:ring-2 focus:ring-red-500 disabled:opacity-50">
            <i class="fas fa-trash-alt text-sm"></i>
            <span class="sr-only">Eliminar habitacion</span>
        </button>
    @endif

    {{-- Historial del dia (siempre disponible) --}}
    <button type="button"
        wire:click="openRoomDailyHistory({{ $room->id }})"
        wire:loading.attr="disabled"
        title="Historial del dia"
        class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 hover:border-gray-300 transition-colors focus:outline-none focus:ring-2 focus:ring-gray-500 disabled:opacity-50">
        <i class="fas fa-history text-sm"></i>
        <span class="sr-only">Historial del dia</span>
    </button>
</div>
