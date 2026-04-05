@extends('layouts.app')

@section('title', 'Detalle de Turno #' . $handover->id)
@section('header', 'Detalle de Turno')

@section('content')
    {{-- Auto-refresh cada 30s si el turno está activo --}}
    @if ($handover->status->value === 'activo')
        <meta http-equiv="refresh" content="30">
    @endif

    <div class="space-y-6">
        <div class="flex justify-between items-center">
            <a href="{{ route('shift-handovers.index') }}" class="text-sm font-bold text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left mr-2"></i> Volver al listado
            </a>
            <div class="flex gap-2">
                @if ($handover->status->value === 'activo')
                    <span
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-100 text-emerald-700 text-sm font-bold">
                        <span class="relative flex h-2.5 w-2.5">
                            <span
                                class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                        </span>
                        EN VIVO - Actualiza cada 30s
                    </span>
                @endif
                <a href="{{ route('shift-handovers.pdf', $handover->id) }}"
                    class="bg-rose-600 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-rose-700 transition-colors">
                    <i class="fas fa-file-pdf mr-2"></i> Descargar PDF
                </a>
            </div>
        </div>

        {{-- Información del turno + Resumen financiero --}}
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            {{-- Info del turno --}}
            <div class="bg-white rounded-xl border border-gray-100 p-6 shadow-sm">
                <h3 class="font-bold text-gray-900 mb-4 uppercase text-xs tracking-wider border-b pb-2">Informacion del
                    Turno</h3>
                <ul class="space-y-4">
                    <li class="flex justify-between items-center text-sm">
                        <span class="text-gray-500">Fecha:</span>
                        <span class="font-bold text-gray-900">{{ $handover->shift_date->format('d/m/Y') }}</span>
                    </li>
                    <li class="flex justify-between items-center text-sm">
                        <span class="text-gray-500">Tipo:</span>
                        <span class="font-bold text-gray-900 uppercase">{{ $handover->shift_type->value }}</span>
                    </li>
                    <li class="flex justify-between items-center text-sm">
                        <span class="text-gray-500">Estado:</span>
                        @php
                            $statusClasses = [
                                'activo' => 'bg-emerald-100 text-emerald-700',
                                'entregado' => 'bg-amber-100 text-amber-700',
                                'recibido' => 'bg-blue-100 text-blue-700',
                                'cerrado' => 'bg-gray-100 text-gray-700',
                            ];
                            $sClass = $statusClasses[$handover->status->value] ?? 'bg-gray-100 text-gray-700';
                        @endphp
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase {{ $sClass }}">
                            {{ $handover->status->value }}
                        </span>
                    </li>
                    <li class="flex justify-between items-center text-sm border-t pt-4">
                        <span class="text-gray-500">Recepcionista:</span>
                        <span class="font-bold text-gray-900">{{ $handover->receptionist_display_name }}</span>
                    </li>
                    <li class="flex justify-between items-center text-sm">
                        <span class="text-gray-500">Recibido por:</span>
                        <span class="font-bold text-gray-900">{{ $handover->recibidoPor->name ?? '—' }}</span>
                    </li>
                    <li class="flex justify-between items-center text-sm border-t pt-4">
                        <span class="text-gray-500">Inicio:</span>
                        <span class="font-bold text-gray-900">{{ $handover->started_at->format('H:i') }}</span>
                    </li>
                    <li class="flex justify-between items-center text-sm">
                        <span class="text-gray-500">Fin:</span>
                        <span
                            class="font-bold text-gray-900">{{ $handover->ended_at ? $handover->ended_at->format('H:i') : '—' }}</span>
                    </li>
                    @if ($handover->started_at)
                        <li class="flex justify-between items-center text-sm">
                            <span class="text-gray-500">Duracion:</span>
                            <span class="font-bold text-gray-900">
                                {{ $handover->ended_at ? $handover->started_at->diff($handover->ended_at)->format('%Hh %Im') : $handover->started_at->diffForHumans(null, true) }}
                            </span>
                        </li>
                    @endif
                </ul>
            </div>

            {{-- Resumen financiero --}}
            <div class="lg:col-span-3">
                <div class="bg-white rounded-xl border border-gray-100 p-6 shadow-sm">
                    <h3 class="font-bold text-gray-900 mb-4 uppercase text-xs tracking-wider border-b pb-2">Resumen
                        Operativo</h3>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-6">
                        <div>
                            <p class="text-xs text-gray-500 font-medium uppercase mb-1">Base Inicial</p>
                            <p class="text-xl font-bold text-gray-900">
                                ${{ number_format($handover->base_inicial, 0, ',', '.') }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 font-medium uppercase mb-1">Ventas Efectivo</p>
                            <p class="text-xl font-bold text-emerald-600">
                                ${{ number_format($handover->total_entradas_efectivo, 0, ',', '.') }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 font-medium uppercase mb-1">Ventas Transferencia</p>
                            <p class="text-xl font-bold text-blue-600">
                                ${{ number_format($handover->total_entradas_transferencia, 0, ',', '.') }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 font-medium uppercase mb-1">Total Salidas</p>
                            <p class="text-xl font-bold text-red-600">
                                ${{ number_format($handover->total_salidas, 0, ',', '.') }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 font-medium uppercase mb-1">Base Esperada</p>
                            <p class="text-xl font-bold text-indigo-600">
                                ${{ number_format($handover->base_esperada, 0, ',', '.') }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 font-medium uppercase mb-1">Base Recibida</p>
                            <p
                                class="text-xl font-bold text-{{ $handover->base_recibida !== null && abs($handover->diferencia) > 0 ? 'red' : 'emerald' }}-600">
                                {{ $handover->base_recibida !== null ? '$' . number_format($handover->base_recibida, 0, ',', '.') : '—' }}
                            </p>
                        </div>
                    </div>

                    @if ($handover->base_recibida !== null && abs($handover->diferencia) > 0)
                        <div
                            class="mt-6 p-4 rounded-lg bg-{{ abs($handover->diferencia) > (float) config('shifts.difference_tolerance', 0) ? 'red' : 'amber' }}-50 border border-{{ abs($handover->diferencia) > (float) config('shifts.difference_tolerance', 0) ? 'red' : 'amber' }}-100 flex items-center justify-between">
                            <div
                                class="flex items-center gap-3 text-{{ abs($handover->diferencia) > (float) config('shifts.difference_tolerance', 0) ? 'red' : 'amber' }}-700 font-bold">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span>Diferencia en caja:</span>
                            </div>
                            <span
                                class="text-lg font-black text-{{ abs($handover->diferencia) > (float) config('shifts.difference_tolerance', 0) ? 'red' : 'amber' }}-700">
                                ${{ number_format($handover->diferencia, 0, ',', '.') }}
                            </span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Habitaciones arrendadas en el turno --}}
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-bold text-gray-900 uppercase text-xs tracking-wider">
                    <i class="fas fa-bed mr-2 text-indigo-500"></i>Habitaciones Arrendadas
                    <span class="ml-2 text-indigo-600">({{ $shiftRentals->count() }})</span>
                </h3>
                @php
                    $rentalsTotal = $shiftRentals->sum(function ($stay) use ($handover) {
                        $reservation = $stay->reservation;
                        if (!$reservation) {
                            return 0;
                        }

                        $reservationRoom =
                            $reservation->reservationRooms?->firstWhere('room_id', $stay->room_id) ??
                            $reservation->reservationRooms?->first();

                        $subtotal = (float) ($reservationRoom->subtotal ?? 0);
                        $totalAmount = (float) ($reservation->total_amount ?? 0);
                        $rowTotal = $subtotal > 0 ? $subtotal : $totalAmount;
                        
                        if ($rowTotal <= 0) {
                            return 0;
                        }

                        // Calcular días totales de la reserva
                        $checkInDate = $reservationRoom?->check_in_date 
                            ? \Carbon\Carbon::parse($reservationRoom->check_in_date)
                            : $reservation->created_at?->startOfDay();
                        $checkOutDate = $reservationRoom?->check_out_date 
                            ? \Carbon\Carbon::parse($reservationRoom->check_out_date)
                            : null;
                        
                        if (!$checkInDate || !$checkOutDate) {
                            return 0;
                        }

                        $totalDays = $checkInDate->diffInDays($checkOutDate);
                        if ($totalDays <= 0) {
                            return 0;
                        }

                        // Calcular días trabajados en este turno
                        $shiftStart = $handover->started_at->startOfDay();
                        $shiftEnd = $handover->ended_at ? $handover->ended_at->endOfDay() : now()->endOfDay();
                        
                        // El turno solo puede contar días que están dentro del rango de la reserva
                        $periodStart = max($checkInDate, $shiftStart);
                        $periodEnd = min($checkOutDate, $shiftEnd);
                        
                        if ($periodStart >= $periodEnd) {
                            return 0;
                        }
                        
                        $workedDays = $periodStart->diffInDays($periodEnd);
                        
                        // Calcular valor proporcional a los días trabajados
                        $dailyRate = $rowTotal / $totalDays;
                        return $dailyRate * $workedDays;
                    });
                @endphp
                <span class="text-sm font-bold text-gray-900">
                    Total: ${{ number_format($rentalsTotal, 0, ',', '.') }}
                </span>
            </div>
            <div class="p-0">
                @if ($shiftRentals->isEmpty())
                    <p class="p-6 text-sm text-gray-500 text-center">No hay habitaciones arrendadas en este turno</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Hora</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">
                                        Habitación</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Huésped
                                    </th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Reserva
                                    </th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Check-out
                                    </th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Estado
                                        Pago</th>
                                    <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase">Valor
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                @foreach ($shiftRentals as $stay)
                                    @php
                                        $reservation = $stay->reservation;
                                        $reservationRoom =
                                            $reservation?->reservationRooms?->firstWhere('room_id', $stay->room_id) ??
                                            $reservation?->reservationRooms?->first();
                                        $subtotal = (float) ($reservationRoom->subtotal ?? 0);
                                        $totalAmount = (float) ($reservation->total_amount ?? 0);
                                        $fullRowTotal = $subtotal > 0 ? $subtotal : $totalAmount;
                                        $paidInShift = (float) ($stay->paid_in_shift ?? 0);
                                        $paidTotal = (float) ($stay->reservation_paid_total ?? 0);

                                        // Calcular valor proporcional para días trabajados en este turno
                                        $rowTotal = 0;
                                        if ($fullRowTotal > 0) {
                                            // Calcular días totales de la reserva
                                            $checkInDate = $reservationRoom?->check_in_date 
                                                ? \Carbon\Carbon::parse($reservationRoom->check_in_date)
                                                : $reservation->created_at?->startOfDay();
                                            $checkOutDate = $reservationRoom?->check_out_date 
                                                ? \Carbon\Carbon::parse($reservationRoom->check_out_date)
                                                : null;
                                            
                                            if ($checkInDate && $checkOutDate) {
                                                $totalDays = $checkInDate->diffInDays($checkOutDate);
                                                if ($totalDays > 0) {
                                                    // Calcular días trabajados en este turno
                                                    $shiftStart = $handover->started_at->startOfDay();
                                                    $shiftEnd = $handover->ended_at ? $handover->ended_at->endOfDay() : now()->endOfDay();
                                                    
                                                    // El turno solo puede contar días que están dentro del rango de la reserva
                                                    $periodStart = max($checkInDate, $shiftStart);
                                                    $periodEnd = min($checkOutDate, $shiftEnd);
                                                    
                                                    if ($periodStart < $periodEnd) {
                                                        $workedDays = $periodStart->diffInDays($periodEnd);
                                                        $dailyRate = $fullRowTotal / $totalDays;
                                                        $rowTotal = $dailyRate * $workedDays;
                                                    }
                                                }
                                            }
                                        }

                                        $isPaid = $fullRowTotal > 0 && $paidTotal >= $fullRowTotal - 0.01;
                                        $isPartial = !$isPaid && $paidTotal > 0;

                                        if ($paidInShift > 0 && $isPaid) {
                                            $statusClass = 'bg-emerald-100 text-emerald-700 border-emerald-200';
                                            $statusLabel = 'Pagado en turno';
                                        } elseif ($paidInShift > 0) {
                                            $statusClass = 'bg-amber-100 text-amber-700 border-amber-200';
                                            $statusLabel = 'Abonado en turno';
                                        } elseif ($isPaid) {
                                            $statusClass = 'bg-blue-100 text-blue-700 border-blue-200';
                                            $statusLabel = 'Pagado (otro turno)';
                                        } elseif ($isPartial) {
                                            $statusClass = 'bg-amber-100 text-amber-700 border-amber-200';
                                            $statusLabel = 'Abonado (otro turno)';
                                        } else {
                                            $statusClass = 'bg-red-100 text-red-700 border-red-200';
                                            $statusLabel = 'Pendiente';
                                        }
                                    @endphp
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                            {{ optional($stay->check_in_at)->format('H:i') ?? 'N/A' }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-bold text-gray-900">
                                            Hab. {{ $stay->room->room_number ?? 'N/A' }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-700">
                                            {{ $reservation->customer->name ?? 'Sin cliente asignado' }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500 font-mono">
                                            {{ $reservation->reservation_code ?? '#' . ($reservation->id ?? 'N/A') }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
                                            {{ $reservationRoom && $reservationRoom->check_out_date ? \Carbon\Carbon::parse($reservationRoom->check_out_date)->format('d/m/Y') : 'N/A' }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="flex flex-col gap-1">
                                                <span
                                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border w-fit {{ $statusClass }}">
                                                    {{ $statusLabel }}
                                                </span>
                                                <span class="text-[11px] text-gray-500">
                                                    Turno: ${{ number_format($paidInShift, 0, ',', '.') }}
                                                </span>
                                            </div>
                                        </td>
                                        <td
                                            class="px-4 py-3 whitespace-nowrap text-sm text-right font-bold text-indigo-700">
                                            ${{ number_format($rowTotal, 0, ',', '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- Abonos de Reservas del Turno --}}
        @php
            $shiftPayments = $shiftPayments ?? collect();
            // IDs de pagos que ya tienen reversa dentro de este turno
            $alreadyReversedIds = $shiftPayments
                ->filter(fn($p) => (float) $p->amount < 0 && preg_match('/anulacion\s+de\s+pago\s*#\s*(\d+)/i', $p->reference ?? '', $m))
                ->map(fn($p) => (int) (preg_match('/anulacion\s+de\s+pago\s*#\s*(\d+)/i', $p->reference ?? '', $m) ? $m[1] : 0))
                ->filter()
                ->values()
                ->toArray();
            
            // Calcular total neto excluyendo pagos revertidos
            $paymentsNetTotal = $shiftPayments
                ->filter(fn($p) => !in_array($p->id, $alreadyReversedIds))
                ->sum(fn($p) => (float) $p->amount);
        @endphp
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-bold text-gray-900 uppercase text-xs tracking-wider">
                    <i class="fas fa-file-invoice-dollar mr-2 text-cyan-500"></i>Pagos y Abonos de Reservas
                    <span class="ml-2 text-cyan-600">({{ $shiftPayments->where('amount', '>', 0)->count() }})</span>
                </h3>
                <span class="text-sm font-bold {{ $paymentsNetTotal >= 0 ? 'text-cyan-700' : 'text-red-600' }}">
                    Total neto: ${{ number_format(abs($paymentsNetTotal), 0, ',', '.') }}
                </span>
            </div>
            <div class="p-0">
                @if ($shiftPayments->isEmpty())
                    <p class="p-6 text-sm text-gray-500 text-center">No hay abonos de reservas en este turno</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Hora
                                    </th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Forma
                                    </th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Reserva
                                    </th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Huesped
                                    </th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">
                                        Habitacion</th>
                                    <th class="px-4 py-3 text-center text-[10px] font-black text-gray-500 uppercase">Metodo
                                    </th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Banco / Ref
                                    </th>
                                    <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase">Monto
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                @foreach ($shiftPayments as $payment)
                                    @php
                                        $res = $payment->reservation;
                                        $rooms = $res?->reservationRooms
                                            ?->pluck('room.room_number')
                                            ->filter()
                                            ->unique()
                                            ->implode(', ');
                                        $isReversal = (float) $payment->amount < 0;
                                        $alreadyReversed = in_array($payment->id, $alreadyReversedIds);
                                    @endphp
                                    @if ($isReversal || $alreadyReversed)
                                        @continue
                                    @endif
                                    @php
                                        $pmCode = strtolower(
                                            $payment->paymentMethod?->code ?? ($payment->paymentMethod?->name ?? ''),
                                        );
                                        $methodLabel = match (true) {
                                            str_contains($pmCode, 'efectivo') || str_contains($pmCode, 'cash')
                                                => 'efectivo',
                                            str_contains($pmCode, 'transferencia') || str_contains($pmCode, 'transfer')
                                                => 'transferencia',
                                            default => $pmCode ?: 'otro',
                                        };
                                        $methodClass = match ($methodLabel) {
                                            'efectivo' => 'bg-emerald-100 text-emerald-700',
                                            'transferencia' => 'bg-blue-100 text-blue-700',
                                            default => 'bg-gray-100 text-gray-700',
                                        };
                                    @endphp
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                            {{ optional($payment->paid_at ?? $payment->created_at)->format('H:i') }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-center">
                                            @php
                                                $resCode = $res?->reservation_code ?? '';
                                                $isWalkInOld     = str_starts_with($resCode, 'RSV');
                                                $isWalkInNew = str_starts_with($resCode, 'WLK');
                                            @endphp
                                            @if ($isWalkInOld or $isWalkInNew)
                                                <span
                                                    class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-100 text-emerald-700">
                                                    Arrendada
                                                </span>
                                            @else
                                                <span
                                                    class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-blue-100 text-blue-700">
                                                    Reserva
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-xs font-mono text-gray-600">
                                            {{ $res?->reservation_code ?? '#' . ($res?->id ?? 'N/A') }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-700">
                                            {{ $res?->customer?->name ?? 'N/A' }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">
                                            {{ $rooms ? 'Hab. ' . $rooms : '—' }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-center">
                                            <span
                                                class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $methodClass }}">
                                                {{ $methodLabel }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-gray-500">
                                            @if ($methodLabel === 'transferencia')
                                                {{ $payment->bank_name ?? '' }}{{ $payment->bank_name && $payment->reference ? ' / ' : '' }}{{ $payment->reference ?? '' }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td
                                            class="px-4 py-3 whitespace-nowrap text-sm text-right font-bold text-cyan-700">
                                            ${{ number_format(abs((float) $payment->amount), 0, ',', '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- Observaciones --}}
        @if ($handover->observaciones_entrega || $handover->observaciones_recepcion)
            <div class="bg-white rounded-xl border border-gray-100 p-6 shadow-sm">
                <h3 class="font-bold text-gray-900 mb-4 uppercase text-xs tracking-wider border-b pb-2">Observaciones</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase mb-1">Entrega
                            ({{ $handover->receptionist_display_name }})</p>
                        <p class="text-sm text-gray-700 italic">
                            {{ $handover->observaciones_entrega ?: 'Sin observaciones' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium uppercase mb-1">Recepcion
                            ({{ $handover->recibidoPor->name ?? 'N/A' }})</p>
                        <p class="text-sm text-gray-700 italic">
                            {{ $handover->observaciones_recepcion ?: 'Sin observaciones' }}</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Tabla de Ventas --}}
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-bold text-gray-900 uppercase text-xs tracking-wider">
                    <i class="fas fa-shopping-cart mr-2 text-emerald-500"></i>Ventas del Turno
                    <span class="ml-2 text-emerald-600">({{ $handover->sales->count() }})</span>
                </h3>
                <span class="text-sm font-bold text-gray-900">
                    Total: ${{ number_format($handover->sales->sum('total'), 0, ',', '.') }}
                </span>
            </div>
            <div class="p-0">
                @if ($handover->sales->isEmpty())
                    <p class="p-6 text-sm text-gray-500 text-center">No hay ventas registradas en este turno</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">ID</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Fecha
                                    </th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Hora
                                    </th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">
                                        Productos</th>
                                    <th class="px-4 py-3 text-center text-[10px] font-black text-gray-500 uppercase">Metodo
                                    </th>
                                    <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase">Total
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                @foreach ($handover->sales->sortByDesc('created_at') as $sale)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-400 font-mono">
                                            #{{ $sale->id }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                            {{ $sale->created_at->format('d/m/Y') }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                            {{ $sale->created_at->format('H:i') }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">
                                            @foreach ($sale->items->take(3) as $item)
                                                <span class="text-xs">{{ $item->quantity }}x
                                                    {{ $item->product->name ?? 'N/A' }}</span>
                                                @if (!$loop->last)
                                                    ,
                                                @endif
                                            @endforeach
                                            @if ($sale->items->count() > 3)
                                                <span class="text-xs text-gray-400">+{{ $sale->items->count() - 3 }}
                                                    mas</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-center">
                                            @php
                                                $methodClass = match ($sale->payment_method) {
                                                    'efectivo' => 'bg-emerald-100 text-emerald-700',
                                                    'transferencia' => 'bg-blue-100 text-blue-700',
                                                    'ambos' => 'bg-purple-100 text-purple-700',
                                                    default => 'bg-amber-100 text-amber-700',
                                                };
                                            @endphp
                                            <span
                                                class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $methodClass }}">
                                                {{ $sale->payment_method }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-bold text-gray-900">
                                            ${{ number_format($sale->total, 0, ',', '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- Consumos de habitaciones (fiados y pagados) --}}
        @php
            $shiftRoomSales = $shiftRoomSales ?? collect();
            $shiftRoomSalesTotal = (float) $shiftRoomSales->sum('total');
        @endphp
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-bold text-gray-900 uppercase text-xs tracking-wider">
                    <i class="fas fa-concierge-bell mr-2 text-amber-500"></i>Consumos a Habitaciones
                    <span class="ml-2 text-amber-600">({{ $shiftRoomSales->count() }})</span>
                </h3>
                <span class="text-sm font-bold text-gray-900">
                    Total: ${{ number_format($shiftRoomSalesTotal, 0, ',', '.') }}
                </span>
            </div>
            <div class="p-0">
                @if ($shiftRoomSales->isEmpty())
                    <p class="p-6 text-sm text-gray-500 text-center">No hay consumos de habitaciones en este turno</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">ID</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Hora
                                    </th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">
                                        Habitacion</th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Huesped
                                    </th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Producto
                                    </th>
                                    <th class="px-4 py-3 text-center text-[10px] font-black text-gray-500 uppercase">Cant.
                                    </th>
                                    <th class="px-4 py-3 text-center text-[10px] font-black text-gray-500 uppercase">Metodo
                                    </th>
                                    <th class="px-4 py-3 text-center text-[10px] font-black text-gray-500 uppercase">Estado
                                    </th>
                                    <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase">Total
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                @foreach ($shiftRoomSales as $roomSale)
                                    @php
                                        $reservation = $roomSale->reservation;
                                        $roomNumbers = $reservation?->reservationRooms
                                            ?->pluck('room.room_number')
                                            ->filter()
                                            ->unique()
                                            ->values()
                                            ->implode(', ');
                                        $method = (string) ($roomSale->payment_method ?? 'pendiente');
                                        $methodClass = match ($method) {
                                            'efectivo' => 'bg-emerald-100 text-emerald-700',
                                            'transferencia' => 'bg-blue-100 text-blue-700',
                                            default => 'bg-amber-100 text-amber-700',
                                        };
                                        $isPaid = (bool) ($roomSale->is_paid ?? false);
                                    @endphp
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-400 font-mono">
                                            #{{ $roomSale->id }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                            {{ optional($roomSale->created_at)->format('H:i') }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-bold text-gray-900">
                                            {{ $roomNumbers ? 'Hab. ' . $roomNumbers : 'N/A' }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-700">
                                            {{ $reservation?->customer?->name ?? 'Sin cliente' }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-700">
                                            {{ $roomSale->product?->name ?? 'N/A' }}
                                        </td>
                                        <td
                                            class="px-4 py-3 whitespace-nowrap text-sm text-center font-bold text-gray-900">
                                            {{ (int) ($roomSale->quantity ?? 0) }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-center">
                                            <span
                                                class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $methodClass }}">
                                                {{ $method }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-center">
                                            @if ($isPaid)
                                                <span
                                                    class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-100 text-emerald-700">
                                                    Pagado
                                                </span>
                                            @else
                                                <span
                                                    class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-red-100 text-red-700">
                                                    Pendiente
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-bold text-gray-900">
                                            ${{ number_format((float) ($roomSale->total ?? 0), 0, ',', '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6">
            {{-- Gastos del Turno --}}
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="font-bold text-gray-900 uppercase text-xs tracking-wider">
                        <i class="fas fa-money-bill-wave mr-2 text-red-500"></i>Gastos
                        <span class="ml-2 text-red-600">({{ $handover->cashOutflows->count() }})</span>
                    </h3>
                    <span class="text-sm font-bold text-red-600">
                        ${{ number_format($handover->cashOutflows->sum('amount'), 0, ',', '.') }}
                    </span>
                </div>
                <div class="p-0">
                    @if ($handover->cashOutflows->isEmpty())
                        <p class="p-6 text-sm text-gray-500 text-center">Sin gastos</p>
                    @else
                        <table class="min-w-full divide-y divide-gray-100">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Hora
                                    </th>
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Motivo
                                    </th>
                                    <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase">Monto
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                @foreach ($handover->cashOutflows->sortByDesc('created_at') as $outflow)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                            {{ $outflow->created_at->format('H:i') }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ Str::limit($outflow->reason, 40) }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-bold text-red-600">
                                            ${{ number_format($outflow->amount, 0, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>

        </div>

        {{-- Salidas de Productos --}}
        @if ($handover->productOuts->count() > 0)
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="font-bold text-gray-900 uppercase text-xs tracking-wider">
                        <i class="fas fa-box-open mr-2 text-purple-500"></i>Salidas de Productos (Mermas / Consumo)
                        <span class="ml-2 text-purple-600">({{ $handover->productOuts->count() }})</span>
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Hora</th>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Producto
                                </th>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Motivo</th>
                                <th class="px-4 py-3 text-center text-[10px] font-black text-gray-500 uppercase">Cant.</th>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">
                                    Observaciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($handover->productOuts->sortByDesc('created_at') as $out)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ $out->created_at->format('H:i') }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $out->product->name ?? 'N/A' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600">{{ $out->reason->label() }}</td>
                                    <td class="px-4 py-3 text-sm text-center font-bold text-gray-900">
                                        {{ number_format($out->quantity, 0) }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-500">
                                        {{ Str::limit($out->observations, 50) ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
@endsection
