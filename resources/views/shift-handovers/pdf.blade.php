<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Turno #{{ $handover->id }}</title>
    <style>
        @page { margin: 24px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; line-height: 1.5; }
        .header { border-bottom: 2px solid #0f766e; padding-bottom: 10px; margin-bottom: 12px; }
        .header-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: top; }
        .logo-cell { width: 84px; }
        .logo { width: 70px; height: auto; }
        .brand { margin: 0 0 2px; font-size: 13px; font-weight: 800; color: #0f766e; }
        .doc-title { margin: 0; font-size: 12px; font-weight: 700; color: #111827; }
        .doc-subtitle { margin: 3px 0 0; font-size: 9px; color: #4b5563; }
        .meta-right { text-align: right; font-size: 9px; color: #4b5563; }
        .meta-right p { margin: 0 0 3px; }
        .section { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px; margin-bottom: 10px; }
        .section-title { margin: 0 0 8px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #111827; }
        .meta { width: 100%; border-collapse: collapse; }
        .meta td { padding: 4px 6px; vertical-align: top; }
        .k { font-size: 9px; color: #6b7280; text-transform: uppercase; font-weight: 700; letter-spacing: .04em; }
        .v { font-size: 10px; color: #111827; font-weight: 700; }
        .v-red { color: #b91c1c; }
        .v-green { color: #047857; }
        .v-blue { color: #1d4ed8; }
        .v-indigo { color: #4338ca; }
        table.table { width: 100%; border-collapse: collapse; }
        table.table th, table.table td { border-bottom: 1px solid #e5e7eb; padding: 6px 5px; font-size: 10px; }
        table.table th { background: #f3f4f6; text-transform: uppercase; letter-spacing: .04em; color: #374151; text-align: left; font-size: 9px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        table.table tfoot td { border-top: 2px solid #111827; font-weight: 800; padding: 6px 5px; font-size: 10px; }
        .muted { color: #6b7280; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 999px; font-size: 9px; font-weight: 700; text-transform: uppercase; }
        .badge-blue { background: #dbeafe; color: #1d4ed8; }
        .badge-green { background: #dcfce7; color: #047857; }
        .badge-red { background: #fee2e2; color: #b91c1c; }
        .badge-amber { background: #fef3c7; color: #92400e; }
        .footer { margin-top: 12px; padding-top: 8px; border-top: 1px solid #e5e7eb; text-align: center; color: #6b7280; font-size: 9px; }
    </style>
</head>
<body>
    @php
        $shiftRentals = $shiftRentals ?? collect();
        $shiftRoomSales = $shiftRoomSales ?? collect();
        $shiftInventory = $shiftInventory ?? [
            'totals' => [],
            'products' => collect(),
        ];
        $shiftInventoryProducts = $shiftInventory['products'] ?? collect();
        $diff = (float) ($handover->diferencia ?? 0);
        $logoDataUri = app(\App\Services\PdfBrandingService::class)->getHotelLogoDataUri();
    @endphp

    <div class="header">
        <table class="header-table">
            <tr>
                <td class="logo-cell">
                    @if($logoDataUri)
                        <img src="{{ $logoDataUri }}" alt="Hotel San Pedro" class="logo">
                    @endif
                </td>
                <td>
                    <p class="brand">Hotel San Pedro</p>
                    <p class="doc-title">Detalle de Turno #{{ $handover->id }}</p>
                    <p class="doc-subtitle">
                        Fecha: {{ $handover->shift_date->format('d/m/Y') }}
                        | Tipo: {{ strtoupper($handover->shift_type->value) }}
                    </p>
                </td>
                <td class="meta-right">
                    <p>Estado: <span class="badge badge-blue">{{ $handover->status->value }}</span></p>
                    <p>Generado: {{ now()->format('d/m/Y H:i') }}</p>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Informacion del Turno</div>
        <table class="meta">
            <tr>
                <td style="width: 33%">
                    <div class="k">Recepcionista</div>
                    <div class="v">{{ $handover->receptionist_display_name }}</div>
                </td>
                <td style="width: 33%">
                    <div class="k">Recibido por</div>
                    <div class="v">{{ $handover->recibidoPor->name ?? 'Pendiente' }}</div>
                </td>
                <td style="width: 34%">
                    <div class="k">Inicio / Fin</div>
                    <div class="v">
                        {{ optional($handover->started_at)->format('d/m/Y H:i') ?? 'N/A' }}
                        -
                        {{ optional($handover->ended_at)->format('d/m/Y H:i') ?? 'N/A' }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Resumen Operativo</div>
        <table class="meta">
            <tr>
                <td style="width: 33%">
                    <div class="k">Base Inicial</div>
                    <div class="v">${{ number_format($handover->base_inicial ?? 0, 0, ',', '.') }}</div>
                </td>
                <td style="width: 33%">
                    <div class="k">Entradas Efectivo</div>
                    <div class="v v-green">${{ number_format($handover->total_entradas_efectivo ?? 0, 0, ',', '.') }}</div>
                </td>
                <td style="width: 34%">
                    <div class="k">Entradas Transferencia</div>
                    <div class="v v-blue">${{ number_format($handover->total_entradas_transferencia ?? 0, 0, ',', '.') }}</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="k">Total Salidas</div>
                    <div class="v v-red">${{ number_format($handover->total_salidas ?? 0, 0, ',', '.') }}</div>
                </td>
                <td>
                    <div class="k">Base Esperada</div>
                    <div class="v v-indigo">${{ number_format($handover->base_esperada ?? 0, 0, ',', '.') }}</div>
                </td>
                <td>
                    <div class="k">Base Recibida / Diferencia</div>
                    <div class="v {{ abs($diff) > ($tolerance ?? 0) ? 'v-red' : 'v-green' }}">
                        ${{ number_format($handover->base_recibida ?? 0, 0, ',', '.') }}
                        ({{ $diff >= 0 ? '+' : '' }}${{ number_format($diff, 0, ',', '.') }})
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Habitaciones Arrendadas ({{ $shiftRentals->count() }})</div>
        @if($shiftRentals->isEmpty())
            <div class="muted">No hay habitaciones arrendadas en este turno.</div>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Habitacion</th>
                        <th>Huesped</th>
                        <th>Reserva</th>
                        <th>Estado pago</th>
                        <th class="text-right">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shiftRentals as $stay)
                        @php
                            $reservation = $stay->reservation;
                            $reservationRoom = $reservation?->reservationRooms
                                ?->firstWhere('room_id', $stay->room_id) ?? $reservation?->reservationRooms?->first();
                            $rowTotal = (float) ($stay->rental_total ?? (($reservationRoom->subtotal ?? 0) ?: ($reservation->total_amount ?? 0)));
                            $paidInShift = (float) ($stay->paid_in_shift ?? 0);
                            $paidTotal = (float) ($stay->reservation_paid_total ?? 0);
                            $isPaid = $rowTotal > 0 && $paidTotal >= ($rowTotal - 0.01);
                            $isPartial = !$isPaid && $paidTotal > 0;
                            if ($paidInShift > 0 && $isPaid) {
                                $payLabel = 'Pagado en turno';
                            } elseif ($paidInShift > 0) {
                                $payLabel = 'Abonado en turno';
                            } elseif ($isPaid) {
                                $payLabel = 'Pagado (otro turno)';
                            } elseif ($isPartial) {
                                $payLabel = 'Abonado (otro turno)';
                            } else {
                                $payLabel = 'Pendiente';
                            }
                        @endphp
                        <tr>
                            <td>{{ optional($stay->check_in_at)?->translatedFormat('D d/m') ?? 'N/A' }}</td>
                            <td>{{ optional($stay->check_in_at)->format('H:i') ?? 'N/A' }}</td>
                            <td>Hab. {{ $stay->room->room_number ?? 'N/A' }}</td>
                            <td>{{ $reservation?->customer?->name ?? 'Sin cliente' }}</td>
                            <td>{{ $reservation->reservation_code ?? ('#' . ($reservation->id ?? 'N/A')) }}</td>
                            <td>{{ $payLabel }} (Turno: ${{ number_format($paidInShift, 0, ',', '.') }})</td>
                            <td class="text-right">${{ number_format($rowTotal, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="6" class="text-right">Total</td>
                        <td class="text-right">${{ number_format($shiftRentals->sum(fn($s) => (float) ($s->rental_total ?? (($s->reservation?->reservationRooms?->firstWhere('room_id', $s->room_id)?->subtotal ?? 0) ?: ($s->reservation?->total_amount ?? 0)))), 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>

    <div class="section">
        <div class="section-title">Ventas del Turno (POS) ({{ $handover->sales->count() }})</div>
        @if($handover->sales->isEmpty())
            <div class="muted">No hay ventas POS registradas en este turno.</div>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>Productos</th>
                        <th class="text-center">Metodo</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($handover->sales->sortByDesc('created_at') as $sale)
                        <tr>
                            <td>{{ optional($sale->created_at)->format('H:i') }}</td>
                            <td>
                                @foreach($sale->items->take(3) as $item)
                                    {{ $item->quantity }}x {{ $item->product->name ?? 'N/A' }}@if(!$loop->last), @endif
                                @endforeach
                                @if($sale->items->count() > 3)
                                    +{{ $sale->items->count() - 3 }} mas
                                @endif
                            </td>
                            <td class="text-center">{{ $sale->payment_method }}</td>
                            <td class="text-right">${{ number_format((float) ($sale->total ?? 0), 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="text-right">Total</td>
                        <td class="text-right">${{ number_format($handover->sales->sum(fn($s) => (float) ($s->total ?? 0)), 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>

    <div class="section">
        <div class="section-title">Consumos a Habitaciones ({{ $shiftRoomSales->count() }})</div>
        @if($shiftRoomSales->isEmpty())
            <div class="muted">No hay consumos de habitaciones en este turno.</div>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Hora</th>
                        <th>Habitacion</th>
                        <th>Huesped</th>
                        <th>Producto</th>
                        <th class="text-center">Cant.</th>
                        <th class="text-center">Metodo</th>
                        <th class="text-center">Estado</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shiftRoomSales as $roomSale)
                        @php
                            $reservation = $roomSale->reservation;
                            $roomNumbers = $reservation?->reservationRooms
                                ?->pluck('room.room_number')
                                ->filter()
                                ->unique()
                                ->values()
                                ->implode(', ');
                        @endphp
                        <tr>
                            <td>#{{ $roomSale->id }}</td>
                            <td>{{ optional($roomSale->created_at)->format('H:i') }}</td>
                            <td>{{ $roomNumbers ? 'Hab. ' . $roomNumbers : 'N/A' }}</td>
                            <td>{{ $reservation?->customer?->name ?? 'Sin cliente' }}</td>
                            <td>{{ $roomSale->product?->name ?? 'N/A' }}</td>
                            <td class="text-center">{{ (int) ($roomSale->quantity ?? 0) }}</td>
                            <td class="text-center">{{ $roomSale->payment_method ?? 'pendiente' }}</td>
                            <td class="text-center">{{ (bool) ($roomSale->is_paid ?? false) ? 'Pagado' : 'Pendiente' }}</td>
                            <td class="text-right">${{ number_format((float) ($roomSale->total ?? 0), 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="8" class="text-right">Total</td>
                        <td class="text-right">${{ number_format($shiftRoomSales->sum(fn($s) => (float) ($s->total ?? 0)), 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>

    <div class="section">
        <div class="section-title">Inventario del Turno</div>
        @if($shiftInventoryProducts->isEmpty())
            <div class="muted">Sin movimientos de inventario en este turno.</div>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th class="text-right">Recibido</th>
                        <th class="text-right">Ventas</th>
                        <th class="text-right">Consumo Hab.</th>
                        <th class="text-right">Entrega</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($shiftInventoryProducts as $productRow)
                        <tr>
                            <td>
                                {{ $productRow['product_name'] ?? 'Producto' }}
                                @if(!empty($productRow['product_sku']))
                                    <span class="muted">({{ $productRow['product_sku'] }})</span>
                                @endif
                            </td>
                            <td class="text-right">{{ number_format((float) ($productRow['opening'] ?? 0), 0, ',', '.') }}</td>
                            <td class="text-right">{{ number_format((float) ($productRow['sales'] ?? 0), 0, ',', '.') }}</td>
                            <td class="text-right">{{ number_format((float) ($productRow['room_consumption'] ?? 0), 0, ',', '.') }}</td>
                            <td class="text-right">{{ number_format((float) ($productRow['closing'] ?? 0), 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if($handover->cashOutflows->isNotEmpty())
        <div class="section">
            <div class="section-title">Gastos del Turno ({{ $handover->cashOutflows->count() }})</div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Motivo</th>
                        <th class="text-right">Monto</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($handover->cashOutflows->sortByDesc('created_at') as $outflow)
                        <tr>
                            <td>{{ optional($outflow->created_at)?->translatedFormat('D d/m') ?? 'N/A' }}</td>
                            <td>{{ optional($outflow->created_at)->format('H:i') }}</td>
                            <td>{{ $outflow->reason }}</td>
                            <td class="text-right">${{ number_format((float) ($outflow->amount ?? 0), 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="text-right">Total</td>
                        <td class="text-right">${{ number_format($handover->cashOutflows->sum(fn($o) => (float) ($o->amount ?? 0)), 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    @if($handover->productOuts->isNotEmpty())
        <div class="section">
            <div class="section-title">Salidas de Productos ({{ $handover->productOuts->count() }})</div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>Producto</th>
                        <th>Motivo</th>
                        <th class="text-center">Cant.</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($handover->productOuts->sortByDesc('created_at') as $po)
                        <tr>
                            <td>{{ optional($po->created_at)->format('H:i') }}</td>
                            <td>{{ $po->product->name ?? 'N/A' }}</td>
                            <td>{{ $po->reason->label() }}</td>
                            <td class="text-center">{{ number_format((float) ($po->quantity ?? 0), 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="section">
        <div class="section-title">Observaciones</div>
        <table class="meta">
            <tr>
                <td style="width: 50%">
                    <div class="k">Entrega</div>
                    <div>{{ $handover->observaciones_entrega ?: 'Sin observaciones' }}</div>
                </td>
                <td style="width: 50%">
                    <div class="k">Recepcion</div>
                    <div>{{ $handover->observaciones_recepcion ?: 'Sin observaciones' }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="footer">
        Generado: {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
