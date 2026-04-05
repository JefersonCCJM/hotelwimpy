{{-- Tablas de detalle del turno activo --}}
@if ($activeShift)
    @php
        $shiftRoomDebtSales = $shiftRoomDebtSales ?? collect();
        $totalShiftSalesCount = $shiftSales->count() + $shiftRoomDebtSales->count();
        $shiftInventory = $shiftInventory ?? [
            'totals' => [],
            'products' => collect(),
        ];
        $shiftInventoryProducts = $shiftInventory['products'] ?? collect();
        $shiftPayments = $shiftPayments ?? collect();
    @endphp

    <!-- Acciones Rápidas -->
    <div class="bg-white rounded-xl border border-gray-100 p-6 shadow-sm">
        <h3 class="font-bold text-gray-900 mb-4 uppercase text-xs tracking-wider">Acciones Rapidas</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @can('create_sales')
                <a href="{{ route('sales.create') }}"
                    class="flex flex-col items-center p-4 rounded-xl border border-emerald-100 bg-emerald-50 hover:bg-emerald-100 transition-colors">
                    <i class="fas fa-cart-plus text-emerald-600 mb-2"></i>
                    <span class="text-xs font-semibold text-emerald-700">Nueva Venta</span>
                </a>
            @endcan

            <a href="{{ route('rooms.index') }}"
                class="flex flex-col items-center p-4 rounded-xl border border-blue-100 bg-blue-50 hover:bg-blue-100 transition-colors">
                <i class="fas fa-bed text-blue-600 mb-2"></i>
                <span class="text-xs font-semibold text-blue-700">Habitaciones</span>
            </a>

            @can('manage_cash_outflows')
                <a href="{{ route('cash-outflows.index') }}"
                    class="flex flex-col items-center p-4 rounded-xl border border-red-100 bg-red-50 hover:bg-red-100 transition-colors">
                    <i class="fas fa-money-bill-wave text-red-600 mb-2"></i>
                    <span class="text-xs font-semibold text-red-700">Nuevo Gasto</span>
                </a>
            @endcan

            <a href="{{ route('shift-product-outs.create') }}"
                class="flex flex-col items-center p-4 rounded-xl border border-purple-100 bg-purple-50 hover:bg-purple-100 transition-colors">
                <i class="fas fa-box-open text-purple-600 mb-2"></i>
                <span class="text-xs font-semibold text-purple-700">Salida Producto</span>
            </a>

            @can('create_customers')
                <a href="{{ route('customers.create') }}"
                    class="flex flex-col items-center p-4 rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 transition-colors">
                    <i class="fas fa-user-plus text-gray-600 mb-2"></i>
                    <span class="text-xs font-semibold text-gray-700">Nuevo Cliente</span>
                </a>
            @endcan
        </div>
    </div>

    <!-- Ventas del Turno -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-bold text-gray-900 uppercase text-xs tracking-wider">
                <i class="fas fa-shopping-cart mr-2 text-emerald-500"></i>Ventas del Turno
                <span class="ml-2 text-emerald-600">({{ $totalShiftSalesCount }})</span>
            </h3>
            <a href="{{ route('sales.index') }}" class="text-xs text-blue-600 hover:underline">Ver todas</a>
        </div>
        <div class="p-0">
            @if ($shiftSales->isEmpty() && $shiftRoomDebtSales->isEmpty())
                <p class="p-6 text-sm text-gray-500 text-center">No hay ventas registradas en este turno</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Fecha</th>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Hora</th>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Productos
                                </th>
                                <th class="px-4 py-3 text-center text-[10px] font-black text-gray-500 uppercase">Metodo
                                </th>
                                <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase">Total
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach ($shiftSales as $sale)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ $sale->created_at->format('d/m/Y') }} ({{ $sale->created_at->locale('es')->isoFormat('ddd') }})
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ $sale->created_at->format('H:i') }}
                                    </td>
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

                            @foreach ($shiftRoomDebtSales as $roomDebtSale)
                                @php
                                    $reservation = $roomDebtSale->reservation;
                                    $reservationRoom = $reservation?->reservationRooms?->first();
                                    $roomNumber = $reservationRoom?->room?->room_number;
                                    $customerName = $reservation?->customer?->name;
                                @endphp
                                <tr class="hover:bg-red-50/30">
                                    <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ optional($roomDebtSale->created_at)->format('d/m/Y') }} ({{ optional($roomDebtSale->created_at)->locale('es')->isoFormat('ddd') }})
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ optional($roomDebtSale->created_at)->format('H:i') }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-700">
                                        <div class="flex flex-col">
                                            <span class="text-xs">{{ (int) ($roomDebtSale->quantity ?? 0) }}x
                                                {{ $roomDebtSale->product->name ?? 'N/A' }}</span>
                                            <span class="text-[11px] text-gray-400">
                                                Hab. {{ $roomNumber ?? 'N/A' }} · {{ $customerName ?? 'Sin cliente' }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-center">
                                        <span
                                            class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-red-100 text-red-700">
                                            deuda habitacion
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-bold text-red-700">
                                        ${{ number_format((float) ($roomDebtSale->total ?? 0), 0, ',', '.') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="4" class="px-4 py-3 text-right text-sm font-bold text-gray-900">Total</td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-bold text-gray-900">
                                    ${{ number_format($shiftSales->sum(fn($s) => (float) ($s->total ?? 0)) + $shiftRoomDebtSales->sum(fn($s) => (float) ($s->total ?? 0)), 0, ',', '.') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <!-- Inventario del Turno -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="font-bold text-gray-900 uppercase text-xs tracking-wider">
                <i class="fas fa-boxes mr-2 text-indigo-500"></i>Inventario del Turno
            </h3>
        </div>

        <div class="p-0">
            @if ($shiftInventoryProducts->isEmpty())
                <p class="p-6 text-sm text-gray-500 text-center">Sin movimientos de inventario en este turno.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Producto
                                </th>
                                <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase">Recibido
                                </th>
                                <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase">Ventas
                                </th>
                                <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase">Consumo
                                    Hab.</th>
                                <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase">Entrega
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach ($shiftInventoryProducts as $productRow)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm text-gray-700">
                                        <div class="flex flex-col">
                                            <span
                                                class="font-semibold text-gray-900">{{ $productRow['product_name'] ?? 'Producto' }}</span>
                                            @if (!empty($productRow['product_sku']))
                                                <span class="text-[11px] text-gray-400">SKU:
                                                    {{ $productRow['product_sku'] }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-bold text-gray-900">
                                        {{ number_format((float) ($productRow['opening'] ?? 0), 0, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-blue-700">
                                        {{ number_format((float) ($productRow['sales'] ?? 0), 0, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-cyan-700">
                                        {{ number_format((float) ($productRow['room_consumption'] ?? 0), 0, ',', '.') }}
                                    </td>
                                    <td
                                        class="px-4 py-3 whitespace-nowrap text-sm text-right font-bold text-indigo-700">
                                        {{ number_format((float) ($productRow['closing'] ?? 0), 0, ',', '.') }}
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
        <!-- Gastos del Turno -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-bold text-gray-900 uppercase text-xs tracking-wider">
                    <i class="fas fa-money-bill-wave mr-2 text-red-500"></i>Gastos
                    <span class="ml-2 text-red-600">({{ $shiftOutflows->count() }})</span>
                </h3>
                <a href="{{ route('cash-outflows.index') }}" class="text-xs text-blue-600 hover:underline">Ver
                    todos</a>
            </div>
            <div class="p-0">
                @if ($shiftOutflows->isEmpty())
                    <p class="p-6 text-sm text-gray-500 text-center">Sin gastos registrados</p>
                @else
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Fecha</th>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Hora</th>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Motivo</th>
                                <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase">Monto</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach ($shiftOutflows as $outflow)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ $outflow->created_at->format('d/m/Y') }} ({{ $outflow->created_at->locale('es')->isoFormat('ddd') }})
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ $outflow->created_at->format('H:i') }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ Str::limit($outflow->reason, 40) }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-bold text-red-600">
                                        ${{ number_format($outflow->amount, 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="3" class="px-4 py-3 text-right text-sm font-bold text-gray-900">Total</td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-bold text-red-600">
                                    ${{ number_format($shiftOutflows->sum(fn($o) => (float) ($o->amount ?? 0)), 0, ',', '.') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                @endif
            </div>
        </div>

    </div>

    <!-- Abonos de Reservas -->
    @php
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
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden"
        x-data="{
            reversedInSession: {{ json_encode($alreadyReversedIds) }},
            loading: null,
            async reversePayment(resId, payId, formattedAmount) {
                const result = await Swal.fire({
                    title: '¿Revertir pago?',
                    text: `¿Estás seguro de revertir el pago de $${formattedAmount}? Esta acción no se puede deshacer.`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Sí, revertir',
                    cancelButtonText: 'Cancelar'
                });

                if (!result.isConfirmed) return;

                this.loading = payId;
                try {
                    const resp = await fetch(`/reservations/${resId}/payments/${payId}/cancel`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                        },
                    });
                    const data = await resp.json();
                    if (resp.ok) {
                        this.reversedInSession.push(payId);
                        Swal.fire({
                            title: '¡Pago revertido!',
                            text: 'El pago se anuló correctamente y el dinero fue descontado del turno actual.',
                            icon: 'success',
                            confirmButtonColor: '#10b981'
                        });
                    } else {
                        Swal.fire({
                            title: 'No se pudo revertir',
                            text: data.message ?? 'Error desconocido al revertir el pago.',
                            icon: 'error',
                            confirmButtonColor: '#ef4444'
                        });
                    }
                } catch (e) {
                    Swal.fire({
                        title: 'Error de conexión',
                        text: 'No se pudo comunicar con el servidor.',
                        icon: 'error',
                        confirmButtonColor: '#ef4444'
                    });
                } finally {
                    this.loading = null;
                }
            }
        }">
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
                <p class="p-6 text-sm text-gray-500 text-center">Sin abonos de reservas en este turno</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Fecha</th>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Hora</th>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Forma</th>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Reserva</th>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Huesped</th>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Habitacion</th>
                                <th class="px-4 py-3 text-center text-[10px] font-black text-gray-500 uppercase">Metodo</th>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Banco / Ref</th>
                                <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase">Monto</th>
                                <th class="px-4 py-3 text-center text-[10px] font-black text-gray-500 uppercase">Accion</th>
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
                                    $isPositive = (float) $payment->amount > 0;
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
                                        str_contains($pmCode, 'efectivo') || str_contains($pmCode, 'cash') => 'efectivo',
                                        str_contains($pmCode, 'transferencia') || str_contains($pmCode, 'transfer') => 'transferencia',
                                        default => $pmCode ?: 'otro',
                                    };
                                    $methodClass = match ($methodLabel) {
                                        'efectivo' => 'bg-emerald-100 text-emerald-700',
                                        'transferencia' => 'bg-blue-100 text-blue-700',
                                        default => 'bg-gray-100 text-gray-700',
                                    };
                                    $resCode = $res?->reservation_code ?? '';
                                    $isWalkIn = str_starts_with($resCode, 'RSV') || str_starts_with($resCode, 'WLK');
                                    $formattedAmt = number_format(abs((float) $payment->amount), 0, ',', '.');
                                @endphp
                                @php $paymentDate = $payment->paid_at ?? $payment->created_at; @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ optional($paymentDate)->format('d/m/Y') }} ({{ optional($paymentDate)->locale('es')->isoFormat('ddd') }})
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                        {{ optional($paymentDate)->format('H:i') }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-center">
                                        @if ($isWalkIn)
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-100 text-emerald-700">Arrendada</span>
                                        @else
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-blue-100 text-blue-700">Reserva</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-xs font-mono text-gray-600">
                                        {{ $res?->reservation_code ?? '#' . ($res?->id ?? 'N/A') }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $res?->customer?->name ?? 'N/A' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">
                                        {{ $rooms ? 'Hab. ' . $rooms : '—' }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-center">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase {{ $methodClass }}">
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
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-right font-bold text-cyan-700">
                                        ${{ $formattedAmt }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-center">
                                        @if ($res)
                                            <button
                                                type="button"
                                                x-show="!reversedInSession.includes({{ $payment->id }})"
                                                :disabled="loading === {{ $payment->id }}"
                                                @click="reversePayment({{ $res->id }}, {{ $payment->id }}, '{{ $formattedAmt }}')"
                                                title="Revertir pago"
                                                class="inline-flex items-center justify-center w-7 h-7 rounded-lg border border-red-200 bg-red-50 text-red-600 hover:bg-red-100 hover:border-red-300 transition-colors disabled:opacity-40">
                                                <span x-show="loading !== {{ $payment->id }}"><i class="fas fa-undo text-xs"></i></span>
                                                <span x-show="loading === {{ $payment->id }}"><i class="fas fa-spinner fa-spin text-xs"></i></span>
                                            </button>
                                            <span x-show="reversedInSession.includes({{ $payment->id }})"
                                                class="text-[10px] text-red-500 font-semibold">Revertido</span>
                                        @else
                                            <span class="text-[10px] text-gray-300">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <!-- Salidas de Productos -->
    @if ($shiftProductOuts->isNotEmpty())
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-bold text-gray-900 uppercase text-xs tracking-wider">
                    <i class="fas fa-box-open mr-2 text-purple-500"></i>Salidas de Productos
                    <span class="ml-2 text-purple-600">({{ $shiftProductOuts->count() }})</span>
                </h3>
                <a href="{{ route('shift-product-outs.index') }}" class="text-xs text-blue-600 hover:underline">Ver
                    todas</a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Hora</th>
                            <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Producto
                            </th>
                            <th class="px-4 py-3 text-center text-[10px] font-black text-gray-500 uppercase">Cant.</th>
                            <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase">Motivo</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        @foreach ($shiftProductOuts as $out)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                    {{ $out->created_at->format('H:i') }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700">{{ $out->product->name ?? 'N/A' }}</td>
                                <td class="px-4 py-3 text-sm text-center font-bold text-gray-900">
                                    {{ number_format($out->quantity, 0) }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600">{{ $out->reason->label() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

@endif
