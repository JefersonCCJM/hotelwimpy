@props(['room', 'stay', 'selectedDate' => null])

@php
    use App\Support\HotelTime;

    $isPastDate = $selectedDate
        ? HotelTime::isOperationalPastDate(\Carbon\Carbon::parse($selectedDate))
        : false;

    // SINGLE SOURCE OF TRUTH: Este componente recibe $stay explicitamente
    // GUARD CLAUSE OBLIGATORIO: Si no hay stay, no hay informacion de cuenta para mostrar
    if (!$stay) {
        echo '<span class="text-xs text-gray-400 italic">Cuenta cerrada</span>';
        return;
    }

    // Obtener reserva desde la stay (Single Source of Truth)
    $reservation = $stay->reservation;
    
    // ===============================
    // SSOT FINANCIERO (ALINEADO GLOBAL)
    // ===============================
    
    // Inicializar valores
    $totalAmount = 0;
    $abonoRealizado = 0;
    $refundsTotal = 0;
    $salesDebt = 0;
    $balanceDue = 0;
    
    if ($reservation) {
        // Eager load relations necesarias
        $reservation->loadMissing(['payments', 'sales', 'reservationRooms']);

        // Tomar el rango contractual de esta habitación para excluir la noche de check-out
        $reservationRoom = $reservation->reservationRooms
            ->first(function ($rr) use ($room) {
                return (int) ($rr->room_id ?? 0) === (int) ($room->id ?? 0);
            });
        
        //  NUEVO SSOT: Total del hospedaje desde stay_nights si existe
        try {
            $stayNightsQuery = \App\Models\StayNight::where('reservation_id', $reservation->id)
                ->where('room_id', $room->id);

            if ($reservationRoom && !empty($reservationRoom->check_in_date) && !empty($reservationRoom->check_out_date)) {
                $stayNightsQuery
                    ->whereDate('date', '>=', \Carbon\Carbon::parse((string) $reservationRoom->check_in_date)->toDateString())
                    ->whereDate('date', '<', \Carbon\Carbon::parse((string) $reservationRoom->check_out_date)->toDateString());
            }

            $totalAmount = (float)$stayNightsQuery->sum('price');
            
            // Si no hay noches, usar fallback
            if ($totalAmount <= 0) {
                if ($reservationRoom && !empty($reservationRoom->subtotal)) {
                    $totalAmount = (float)($reservationRoom->subtotal ?? 0);
                } else {
                    $totalAmount = (float)($reservation->total_amount ?? 0);
                }
            }
        } catch (\Exception $e) {
            // Si falla (tabla no existe), usar fallback
            if ($reservationRoom && !empty($reservationRoom->subtotal)) {
                $totalAmount = (float)($reservationRoom->subtotal ?? 0);
            } else {
                $totalAmount = (float)($reservation->total_amount ?? 0);
            }
        }
        
        // Pagos reales (SOLO positivos) - SSOT financiero
        // REGLA CRITICA: Separar pagos y devoluciones para coherencia financiera
        $abonoRealizado = (float)($reservation->payments
            ?->where('amount', '>', 0)
            ->sum('amount') ?? 0);
        
        // Devoluciones (solo negativos, valor absoluto)
        $refundsTotal = abs((float)($reservation->payments
            ?->where('amount', '<', 0)
            ->sum('amount') ?? 0));
        
        // Consumos pendientes
        $salesDebt = (float)($reservation->sales
            ?->where('is_paid', false)
            ->sum('total') ?? 0);
        
        // Balance final (MISMA formula que Room Detail Modal)
        // Formula: deuda = (hospedaje - abonos_reales) + devoluciones + consumos_pendientes
        $balanceDue = ($totalAmount - $abonoRealizado) + $refundsTotal + $salesDebt;
    }
    
    // Para UI: usar abono real, no mezclado
    $paid = $abonoRealizado;
    $nightPaidBadge = $balanceDue <= 0.01
        ? true
        : (bool) ($room->is_night_paid ?? false);
@endphp

@if($reservation)
    {{-- CASO NORMAL: Hay reserva activa con cuenta --}}
    <div class="flex flex-col space-y-1">
        {{-- Badge de estado de noche --}}
        @if(isset($room->is_night_paid))
            @if($nightPaidBadge)
                <span class="inline-flex items-center w-fit px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700 border border-emerald-200">
                    <i class="fas fa-moon mr-1"></i> NOCHE PAGA
                </span>
            @else
                <span class="inline-flex items-center w-fit px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 text-red-700 border border-red-200">
                    <i class="fas fa-moon mr-1"></i> NOCHE PENDIENTE
                </span>
            @endif
        @endif

        {{-- Estado financiero --}}
        @if($balanceDue > 0 && $paid > 0)
            {{-- Pago parcial --}}
            <div class="flex flex-col">
                <span class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Saldo Total</span>
                <span class="text-sm font-bold text-yellow-700">${{ number_format($balanceDue, 0, ',', '.') }}</span>
                <span class="text-[10px] text-gray-500">Abonado: ${{ number_format($paid, 0, ',', '.') }}</span>
            </div>
            <span class="inline-flex items-center w-fit px-2 py-0.5 rounded-full text-[10px] font-semibold bg-yellow-100 text-yellow-700 border border-yellow-200">
                <i class="fas fa-exclamation-circle mr-1"></i> Parcial
            </span>
        @elseif($balanceDue > 0)
            {{-- Pendiente de pago --}}
            <div class="flex flex-col">
                <span class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Saldo Total</span>
                <span class="text-sm font-bold text-red-700">${{ number_format($balanceDue, 0, ',', '.') }}</span>
            </div>
            <span class="inline-flex items-center w-fit px-2 py-0.5 rounded-full text-[10px] font-semibold bg-red-100 text-red-700 border border-red-200">
                <i class="fas fa-exclamation-triangle mr-1"></i> Pendiente
            </span>
        @else
            {{-- Al dia --}}
            <span class="inline-flex items-center w-fit px-2 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                <i class="fas fa-check-circle mr-1"></i> Al dia
            </span>
        @endif

        {{-- Boton Editar Precios --}}
        @if(!$isPastDate)
            <button type="button"
                    wire:click="$parent.openEditPrices({{ $reservation->id }})"
                    class="mt-2 text-xs text-blue-600 hover:text-blue-800 underline font-medium flex items-center space-x-1">
                <i class="fas fa-edit"></i>
                <span>Editar precios</span>
            </button>
        @endif
    </div>
@else
    {{-- CASO EDGE: Stay activo pero sin reserva asociada (inconsistencia de datos) --}}
    <div class="flex flex-col space-y-1">
        <span class="inline-flex items-center w-fit px-2 py-0.5 rounded-full text-[10px] font-semibold bg-yellow-100 text-yellow-700 border border-yellow-200">
            <i class="fas fa-exclamation-triangle mr-1"></i> Sin cuenta asociada
        </span>
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
