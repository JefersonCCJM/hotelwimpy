<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de Reservas</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 10px; }
        .header { text-align: center; border-bottom: 2px solid #10b981; padding-bottom: 10px; margin-bottom: 14px; }
        .title { font-size: 18px; font-weight: bold; color: #059669; margin: 0; }
        .subtitle { font-size: 12px; margin: 2px 0 0 0; color: #374151; }
        .meta { font-size: 9px; margin-top: 4px; color: #6b7280; }
        .summary { margin: 10px 0 14px 0; width: 100%; border-collapse: collapse; }
        .summary td { border: 1px solid #e5e7eb; padding: 6px; }
        .summary .label { font-size: 8px; text-transform: uppercase; color: #6b7280; }
        .summary .value { font-size: 11px; font-weight: bold; color: #111827; margin-top: 2px; display: block; }
        table.report { width: 100%; border-collapse: collapse; }
        table.report th { background: #f3f4f6; font-size: 8px; text-transform: uppercase; border: 1px solid #d1d5db; padding: 6px; text-align: left; }
        table.report td { border: 1px solid #e5e7eb; padding: 5px; vertical-align: top; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .cancelled { color: #b91c1c; font-weight: bold; }
        .active { color: #047857; font-weight: bold; }
        .muted { color: #6b7280; }
        tfoot td { background: #f9fafb; font-weight: bold; }
        .footer { margin-top: 12px; font-size: 8px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <p class="title">HOTEL WIMPY</p>
        <p class="subtitle">Reporte de Reservas</p>
        <p class="subtitle">
            Desde {{ $startDate->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}
            hasta {{ $endDate->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}
        </p>
        <p class="meta">Generado: {{ now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY [a las] HH:mm') }}</p>
    </div>

    <table class="summary">
        <tr>
            <td>
                <span class="label">Total Reservas</span>
                <span class="value">{{ (int) ($totalReservations ?? 0) }}</span>
            </td>
            <td>
                <span class="label">Activas</span>
                <span class="value">{{ (int) ($activeReservations ?? 0) }}</span>
            </td>
            <td>
                <span class="label">Canceladas</span>
                <span class="value">{{ (int) ($cancelledReservations ?? 0) }}</span>
            </td>
            <td>
                <span class="label">Total</span>
                <span class="value">${{ number_format((float) ($totalAmount ?? 0), 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="label">Abonos</span>
                <span class="value">${{ number_format((float) ($totalDeposit ?? 0), 0, ',', '.') }}</span>
            </td>
        </tr>
    </table>

    <table class="report">
        <thead>
            <tr>
                <th style="width: 8%;">Codigo</th>
                <th style="width: 19%;">Cliente</th>
                <th style="width: 17%;">Habitaciones</th>
                <th class="text-center" style="width: 10%;">Check-in</th>
                <th class="text-center" style="width: 10%;">Check-out</th>
                <th class="text-center" style="width: 8%;">Huespedes</th>
                <th class="text-center" style="width: 8%;">Estado</th>
                <th class="text-right" style="width: 10%;">Total</th>
                <th class="text-right" style="width: 10%;">Abono</th>
                <th class="text-right" style="width: 10%;">Saldo</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($reservations as $reservation)
                @php
                    $isCancelled = method_exists($reservation, 'trashed') && $reservation->trashed();
                    $checkInDate = $reservation->reservationRooms->pluck('check_in_date')->filter()->sort()->first();
                    $checkOutDate = $reservation->reservationRooms->pluck('check_out_date')->filter()->sortDesc()->first();
                    $totalAmountRow = (float) ($reservation->total_amount ?? 0);
                    $depositAmountRow = (float) ($reservation->deposit_amount ?? 0);
                    $balanceRow = max(0, $totalAmountRow - $depositAmountRow);
                    $roomNumbers = $reservation->reservationRooms
                        ->map(fn ($reservationRoom) => $reservationRoom->room?->room_number)
                        ->filter()
                        ->unique()
                        ->values()
                        ->implode(', ');
                @endphp
                <tr>
                    <td>{{ $reservation->reservation_code ?? ('RES-' . $reservation->id) }}</td>
                    <td>{{ $reservation->customer->name ?? 'N/A' }}</td>
                    <td>{{ $roomNumbers !== '' ? $roomNumbers : 'N/A' }}</td>
                    <td class="text-center">{{ $checkInDate ? \Carbon\Carbon::parse($checkInDate)->format('d/m/Y') : 'N/A' }}</td>
                    <td class="text-center">{{ $checkOutDate ? \Carbon\Carbon::parse($checkOutDate)->format('d/m/Y') : 'N/A' }}</td>
                    <td class="text-center">{{ (int) ($reservation->total_guests ?? 0) }}</td>
                    <td class="text-center {{ $isCancelled ? 'cancelled' : 'active' }}">
                        {{ $isCancelled ? 'Cancelada' : 'Activa' }}
                    </td>
                    <td class="text-right">${{ number_format($totalAmountRow, 0, ',', '.') }}</td>
                    <td class="text-right">${{ number_format($depositAmountRow, 0, ',', '.') }}</td>
                    <td class="text-right">${{ number_format($balanceRow, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="text-center muted" style="padding: 14px;">
                        No hay reservas en el rango seleccionado.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if (($reservations?->count() ?? 0) > 0)
            @php
                $reportTotal = (float) ($totalAmount ?? 0);
                $reportDeposit = (float) ($totalDeposit ?? 0);
                $reportBalance = max(0, $reportTotal - $reportDeposit);
            @endphp
            <tfoot>
                <tr>
                    <td colspan="7" class="text-right">Totales</td>
                    <td class="text-right">${{ number_format($reportTotal, 0, ',', '.') }}</td>
                    <td class="text-right">${{ number_format($reportDeposit, 0, ',', '.') }}</td>
                    <td class="text-right">${{ number_format($reportBalance, 0, ',', '.') }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

    <p class="footer">Documento generado automaticamente por el sistema Hotel Wimpy.</p>
</body>
</html>
