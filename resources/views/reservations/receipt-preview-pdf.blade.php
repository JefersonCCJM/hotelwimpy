<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Comprobante de Reserva</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.45; }
        .header { border-bottom: 2px solid #10b981; padding-bottom: 12px; margin-bottom: 18px; }
        .title { font-size: 22px; margin: 0; color: #10b981; font-weight: 700; }
        .subtitle { margin: 4px 0 0 0; color: #4b5563; font-size: 12px; }
        .section { margin-top: 14px; }
        .section-title {
            background: #f3f4f6;
            border-left: 4px solid #10b981;
            padding: 6px 10px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #111827;
            margin-bottom: 8px;
        }
        table { width: 100%; border-collapse: collapse; }
        .details td { padding: 5px 2px; vertical-align: top; }
        .label { width: 30%; font-weight: 700; color: #4b5563; }
        .summary th, .summary td { border-bottom: 1px solid #e5e7eb; padding: 8px; }
        .summary th { text-align: left; color: #374151; background: #f9fafb; font-size: 11px; }
        .summary td:last-child, .summary th:last-child { text-align: right; }
        .summary .balance { font-weight: 700; color: #b91c1c; }
        .summary .deposit { color: #047857; }
        .room-list { margin: 0; padding-left: 16px; }
        .room-list li { margin-bottom: 3px; }
        .notes { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px; background: #f9fafb; }
        .footer { margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 10px; font-size: 10px; color: #6b7280; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1 class="title">Hotel Wimpy</h1>
        <p class="subtitle">Comprobante de Reserva</p>
        <p class="subtitle">Fecha de emision: {{ ($issuedAt ?? now())->format('d/m/Y H:i') }}</p>
    </div>

    <div class="section">
        <div class="section-title">Datos del cliente</div>
        <table class="details">
            <tr>
                <td class="label">Nombre</td>
                <td>{{ $customerName ?? 'No disponible' }}</td>
            </tr>
            <tr>
                <td class="label">Documento</td>
                <td>{{ $customerIdentification ?? 'No disponible' }}</td>
            </tr>
            <tr>
                <td class="label">Telefono</td>
                <td>{{ $customerPhone ?? 'No disponible' }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Estancia</div>
        <table class="details">
            <tr>
                <td class="label">Check-in</td>
                <td>{{ $checkInDate ? $checkInDate->format('d/m/Y') : 'No definido' }}{{ !empty($checkInTime) ? ' ' . $checkInTime : '' }}</td>
            </tr>
            <tr>
                <td class="label">Check-out</td>
                <td>{{ $checkOutDate ? $checkOutDate->format('d/m/Y') : 'No definido' }}</td>
            </tr>
            <tr>
                <td class="label">Noches</td>
                <td>{{ max(0, (int) ($nights ?? 0)) }}</td>
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
            <p>No hay habitaciones seleccionadas.</p>
        @endif
    </div>

    <div class="section">
        <div class="section-title">Resumen economico</div>
        <table class="summary">
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th>Valor</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Total reserva</td>
                    <td>${{ number_format((float) ($totalAmount ?? 0), 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td>Abono</td>
                    <td class="deposit">-${{ number_format((float) ($depositAmount ?? 0), 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td>Metodo de pago del abono</td>
                    <td>{{ $paymentMethodLabel ?? (($paymentMethod ?? 'efectivo') === 'transferencia' ? 'Transferencia' : 'Efectivo') }}</td>
                </tr>
                <tr>
                    <td class="balance">Saldo pendiente</td>
                    <td class="balance">${{ number_format((float) ($balanceDue ?? 0), 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td>Estado</td>
                    <td>{{ $status ?? 'Pendiente' }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    @if(!empty($notes))
        <div class="section">
            <div class="section-title">Observaciones</div>
            <div class="notes">{{ $notes }}</div>
        </div>
    @endif

    <div class="footer">
        Este documento sirve como comprobante de la reserva generada en el sistema.
    </div>
</body>
</html>
