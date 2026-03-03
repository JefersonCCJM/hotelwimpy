<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante de Reserva {{ $reservation->reservation_code ?? ('#' . $reservation->id) }}</title>
    <style>
        @page { margin: 24px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; line-height: 1.45; }
        .header { border-bottom: 2px solid #0f766e; padding-bottom: 10px; margin-bottom: 12px; }
        .header-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: top; }
        .logo-cell { width: 84px; }
        .logo { width: 70px; height: auto; }
        .brand { margin: 0 0 2px; font-size: 18px; font-weight: 800; color: #0f766e; }
        .doc-title { margin: 0; font-size: 15px; font-weight: 700; color: #111827; }
        .doc-subtitle { margin: 3px 0 0; font-size: 10px; color: #4b5563; }
        .meta-right { text-align: right; font-size: 10px; color: #4b5563; }
        .meta-right p { margin: 0 0 3px; }
        .section { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px; margin-bottom: 10px; }
        .section-title { margin: 0 0 8px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #111827; }
        .meta { width: 100%; border-collapse: collapse; }
        .meta td { padding: 4px 6px; vertical-align: top; }
        .k { font-size: 9px; color: #6b7280; text-transform: uppercase; font-weight: 700; letter-spacing: .04em; margin-bottom: 2px; }
        .v { font-size: 11px; color: #111827; font-weight: 700; }
        table.table { width: 100%; border-collapse: collapse; }
        table.table th, table.table td { border-bottom: 1px solid #e5e7eb; padding: 6px 5px; font-size: 10px; }
        table.table th { background: #f3f4f6; text-transform: uppercase; letter-spacing: .04em; color: #374151; text-align: left; font-size: 9px; }
        .text-right { text-align: right; }
        .notes { border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px; background: #f9fafb; font-size: 10px; }
        .room-list { margin: 0; padding-left: 16px; }
        .room-list li { margin-bottom: 3px; font-size: 10px; }
        .v-green { color: #047857; }
        .v-red { color: #b91c1c; }
        .footer { margin-top: 12px; padding-top: 8px; border-top: 1px solid #e5e7eb; text-align: center; color: #6b7280; font-size: 9px; }
    </style>
</head>
<body>
    @php
        $logoDataUri = app(\App\Services\PdfBrandingService::class)->getHotelLogoDataUri();
    @endphp

    <div class="header">
        <table class="header-table">
            <tr>
                <td class="logo-cell">
                    @if($logoDataUri)
                        <img src="{{ $logoDataUri }}" alt="Hotel Wimpy" class="logo">
                    @endif
                </td>
                <td>
                    <p class="brand">Hotel Wimpy</p>
                    <p class="doc-title">Comprobante de Reserva</p>
                    <p class="doc-subtitle">Codigo: {{ $reservation->reservation_code ?? ('RES-' . $reservation->id) }}</p>
                </td>
                <td class="meta-right">
                    <p>Fecha emision: {{ ($issuedAt ?? now())->format('d/m/Y H:i') }}</p>
                    <p>Reserva ID: #{{ $reservation->id }}</p>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Datos del Cliente</div>
        <table class="meta">
            <tr>
                <td style="width: 50%">
                    <div class="k">Nombre</div>
                    <div class="v">{{ $reservation->customer?->name ?? 'No disponible' }}</div>
                </td>
                <td style="width: 50%">
                    <div class="k">Documento</div>
                    <div class="v">{{ $reservation->customer?->taxProfile?->identification ?? 'No disponible' }}</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="k">Telefono</div>
                    <div class="v">{{ $reservation->customer?->phone ?? 'No disponible' }}</div>
                </td>
                <td>
                    <div class="k">Correo</div>
                    <div class="v">{{ $reservation->customer?->email ?? 'No disponible' }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Estancia</div>
        <table class="meta">
            <tr>
                <td style="width: 25%">
                    <div class="k">Check-in</div>
                    <div class="v">{{ $checkInDate ? $checkInDate->format('d/m/Y') : 'No definido' }}{{ !empty($checkInTime) ? ' ' . $checkInTime : '' }}</div>
                </td>
                <td style="width: 25%">
                    <div class="k">Check-out</div>
                    <div class="v">{{ $checkOutDate ? $checkOutDate->format('d/m/Y') : 'No definido' }}</div>
                </td>
                <td style="width: 25%">
                    <div class="k">Noches</div>
                    <div class="v">{{ max(0, (int) ($nights ?? 0)) }}</div>
                </td>
                <td style="width: 25%">
                    <div class="k">Huespedes</div>
                    <div class="v">
                        {{ (int) ($reservation->total_guests ?? 0) }}
                        @if(!is_null($reservation->adults) || !is_null($reservation->children))
                            (A: {{ (int) ($reservation->adults ?? 0) }}, N: {{ (int) ($reservation->children ?? 0) }})
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Habitaciones</div>
        @if(!empty($roomSummaries))
            <ul class="room-list">
                @foreach($roomSummaries as $roomSummary)
                    <li>{{ $roomSummary }}</li>
                @endforeach
            </ul>
        @else
            <div class="v">No hay habitaciones asociadas en reservation_rooms.</div>
        @endif
    </div>

    <div class="section">
        <div class="section-title">Resumen Economico</div>
        <table class="table">
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th class="text-right">Valor</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Total reserva</td>
                    <td class="text-right">${{ number_format((float) ($totalAmount ?? 0), 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td>Abono</td>
                    <td class="text-right v-green">-${{ number_format((float) ($depositAmount ?? 0), 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="v-red">Saldo pendiente</td>
                    <td class="text-right v-red">${{ number_format((float) ($balanceDue ?? 0), 0, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    @if(!empty($reservation->notes))
        <div class="section">
            <div class="section-title">Observaciones</div>
            <div class="notes">{{ $reservation->notes }}</div>
        </div>
    @endif

    <div class="footer">
        Este documento sirve como comprobante de la reserva generada en el sistema.
    </div>
</body>
</html>
