@props(['room', 'stay', 'selectedDate' => null])

@php
    use App\Support\HotelTime;
    
    // SINGLE SOURCE OF TRUTH: Este componente recibe $stay explicitamente
    // GUARD CLAUSE OBLIGATORIO: Si no hay stay, no hay informacion de huesped para mostrar
    if (!$stay) {
        echo '<span class="text-xs text-gray-400 italic">Sin huesped</span>';
        return;
    }

    // Obtener reserva desde la stay (Single Source of Truth)
    $reservation = $stay->reservation;
    
    // GUARD CLAUSE: Si no hay reserva, mostrar mensaje apropiado
    if (!$reservation) {
        echo '<span class="text-xs text-amber-600 italic">Sin reserva asociada</span>';
        return;
    }
    
    // SINGLE SOURCE OF TRUTH: Cliente principal SIEMPRE viene de reservation->customer
    //  CRITICO: Verificar client_id directamente primero (puede ser NULL para walk-in sin asignar)
    // NO acceder a $reservation->customer directamente si client_id es NULL para evitar errores
    //  CRITICO: Si reservation tiene client_id pero customer no esta cargado, forzar recarga
    // Esto evita problemas de cache cuando se actualiza el cliente despues de cargar la vista
    if ($reservation->client_id !== null && !$reservation->relationLoaded('customer')) {
        $reservation->load('customer');
    }
    
    // Verificar client_id (columna real en BD) directamente
    $hasCustomerId = !empty($reservation->client_id) && $reservation->client_id !== null;
    $customer = $hasCustomerId ? ($reservation->customer ?? null) : null;
    
    // Obtener ReservationRoom asociado para acceder a huespedes adicionales
    $reservationRoom = $reservation->reservationRooms
        ->firstWhere('room_id', $room->id);
    
    // SINGLE SOURCE OF TRUTH: Huespedes adicionales SIEMPRE vienen de reservationRoom->getGuests()
    // Ruta: reservation_room_guests  reservation_guest_id  reservation_guests.guest_id  customers.id
    $additionalGuests = collect();
    if ($reservationRoom) {
        try {
            $additionalGuests = $reservationRoom->getGuests();
        } catch (\Exception $e) {
            // Silently handle error - no mostrar huespedes adicionales si hay error
            \Log::warning('Error loading additional guests in room-guest-info', [
                'reservation_room_id' => $reservationRoom->id ?? null,
                'error' => $e->getMessage()
            ]);
        }
    }

    $authUser = auth()->user();
    $canEditOccupancy = $authUser
        && (
            $authUser->hasRole('Administrador')
            || $authUser->hasAnyRole(['Recepcionista Día', 'Recepcionista Noche'])
        );
    $isPastDate = $selectedDate
        ? HotelTime::isOperationalPastDate(\Carbon\Carbon::parse($selectedDate))
        : false;
    @endphp

@if($stay && $reservation)
    <div class="space-y-3">
        {{-- Informacion del huesped principal --}}
        <div class="flex items-start space-x-3">
            <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-user text-sm"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-bold text-gray-900 truncate">
                    @if($reservation->customer)
                        <button type="button"
                                wire:click="$parent.showAllGuests({{ $reservation->id }}, {{ $room->id }})"
                                title="{{ $reservation->customer->name }}"
                                class="block w-full truncate text-left text-blue-600 hover:text-blue-800 underline hover:bg-blue-50 px-1 rounded transition-colors">
                            {{ $reservation->customer->name }}
                        </button>
                    @else
                        Huesped no asignado
                    @endif
                </p>
                @if($reservation->customer?->taxProfile)
                    <p class="text-xs text-gray-500">
                        {{ $reservation->customer->taxProfile->identification ?? 'Sin identificacion' }}
                    </p>
                @endif
                
                {{-- BOTON EDITAR/COMPLETAR OCUPACION --}}
                @if($canEditOccupancy && !$isPastDate)
                    <button type="button"
                            wire:click="$parent.openAssignGuests({{ $room->id }})"
                            class="mt-2 text-xs text-blue-600 hover:text-blue-800 underline font-medium flex items-center space-x-1">
                        <i class="fas {{ (!$reservation->customer || !$reservation->client_id) ? 'fa-user-plus' : 'fa-user-edit' }}"></i>
                        <span>{{ (!$reservation->customer || !$reservation->client_id) ? 'Asignar huesped' : 'Editar ocupacion' }}</span>
                    </button>
                @endif
            </div>
        </div>
        
        {{-- Informacion de estancia --}}
        @if($reservationRoom && $reservationRoom->check_in_date)
            <div class="flex items-center space-x-2 text-xs text-gray-600">
                <i class="fas fa-calendar-check"></i>
                <span>Check-in: {{ \Carbon\Carbon::parse($reservationRoom->check_in_date)->format('d/m/Y') }}</span>
            </div>
        @endif
        
        {{-- Informacion de salida --}}
        @if($reservationRoom && $reservationRoom->check_out_date)
            <span class="text-xs text-blue-600 font-medium mt-1">
                Salida: {{ \Carbon\Carbon::parse($reservationRoom->check_out_date)->format('d/m/Y') }}
            </span>
        @endif
        
        {{-- Estado de pago --}}
        <div class="flex items-center space-x-2">
            @php
                $paymentStatus = $reservation->payment_status?->code ?? 'pending';
                $statusConfig = match($paymentStatus) {
                    'paid' => ['text' => 'Pagado', 'color' => 'bg-emerald-100 text-emerald-800'],
                    'partial' => ['text' => 'Parcial', 'color' => 'bg-amber-100 text-amber-800'],
                    'pending' => ['text' => 'Pendiente', 'color' => 'bg-red-100 text-red-800'],
                    default => ['text' => 'Desconocido', 'color' => 'bg-gray-100 text-gray-800'],
                };
            @endphp
            <span class="px-2 py-1 text-xs font-bold rounded-full {{ $statusConfig['color'] }}">
                {{ $statusConfig['text'] }}
            </span>
        </div>
    </div>
@else
    {{-- CASO EDGE: Stay activo pero sin reserva asociada (inconsistencia de datos) --}}
    <div class="flex flex-col space-y-1">
        <div class="flex items-center gap-1.5">
            <i class="fas fa-exclamation-circle text-orange-600 text-xs"></i>
            <span class="text-sm text-orange-700 font-semibold">Sin cuenta asociada</span>
        </div>
        <div class="text-xs text-gray-500">
            No hay reserva ligada a esta estadia.
        </div>
        <button type="button"
                wire:click="$parent.openRoomDetail({{ $room->id }})"
                class="text-xs text-blue-600 hover:text-blue-800 underline font-medium mt-1">
            Ver detalles
        </button>
    </div>
@endif
