<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle de {{ $data['type'] }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #333; line-height: 1.6; padding: 20px; }
        .header { border-bottom: 3px solid #6366f1; margin-bottom: 30px; padding-bottom: 10px; }
        .header h1 { font-size: 20px; color: #6366f1; margin: 0; }
        .section { margin-bottom: 25px; }
        .section-title { font-size: 14px; font-weight: bold; background: #f3f4f6; padding: 5px 10px; border-left: 4px solid #6366f1; margin-bottom: 15px; }
        .grid { display: table; width: 100%; }
        .row { display: table-row; }
        .label { display: table-cell; width: 150px; font-weight: bold; padding: 5px 0; color: #666; }
        .value { display: table-cell; padding: 5px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th { background: #f9fafb; padding: 10px; text-align: left; border-bottom: 2px solid #e5e7eb; font-size: 11px; text-transform: uppercase; }
        td { padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 11px; }
        .footer { margin-top: 50px; text-align: center; font-size: 10px; color: #999; border-top: 1px solid #eee; padding-top: 10px; }
        .status-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        .total-box { margin-top: 20px; text-align: right; border-top: 2px solid #eee; padding-top: 10px; }
        .total-value { font-size: 18px; font-weight: bold; color: #111827; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $data['title'] }}</h1>
        <p>Documento de Control Interno | Generado el: {{ now()->translatedFormat('d \d\e F, Y H:i') }}</p>
    </div>

    <!-- Secciones Dinámicas -->
    @foreach($data as $sectionKey => $sectionData)
        @if(is_array($sectionData) && !in_array($sectionKey, ['items', 'recent_reservations', 'history', 'sales_history', 'audit_history', 'movements', 'inventory_impact']))
            <div class="section">
                <div class="section-title">{{ strtoupper(str_replace('_', ' ', $sectionKey)) }}</div>
                <div class="grid">
                    @foreach($sectionData as $label => $value)
                        <div class="row">
                            <div class="label">{{ $label }}:</div>
                            <div class="value">
                                @if(str_contains(strtolower($label), 'total') || str_contains(strtolower($label), 'monto') || str_contains(strtolower($label), 'precio') || str_contains(strtolower($label), 'saldo') || str_contains(strtolower($label), 'efectivo') || str_contains(strtolower($label), 'transferencia'))
                                    ${{ is_numeric($value) ? number_format($value, 2, ',', '.') : $value }}
                                @else
                                    {{ $value }}
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach

    <!-- Tablas Especiales -->
    @if(isset($data['items']) && count($data['items']) > 0)
        <div class="section">
            <div class="section-title">DESGLOSE DE PRODUCTOS / SERVICIOS</div>
            <table>
                <thead>
                    <tr>
                        <th>Descripción</th>
                        <th style="text-align: center;">Cant.</th>
                        <th style="text-align: right;">P. Unitario</th>
                        <th style="text-align: right;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['items'] as $item)
                        <tr>
                            <td>{{ $item['name'] }}</td>
                            <td style="text-align: center;">{{ $item['quantity'] }}</td>
                            <td style="text-align: right;">${{ number_format($item['price'], 2, ',', '.') }}</td>
                            <td style="text-align: right;">${{ number_format($item['total'], 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="total-box">
                <span style="color: #666;">MONTO TOTAL:</span>
                <span class="total-value">${{ number_format($data['total'] ?? 0, 2, ',', '.') }}</span>
            </div>
        </div>
    @endif

    @if(isset($data['movements']) && count($data['movements']) > 0)
        <div class="section">
            <div class="section-title">HISTORIAL DE MOVIMIENTOS DE STOCK</div>
            <table style="font-size: 9px;">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Cant.</th>
                        <th>Motivo</th>
                        <th>Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['movements'] as $mv)
                        <tr>
                            <td>{{ $mv['date'] }}</td>
                            <td>{{ $mv['type'] }}</td>
                            <td>{{ $mv['qty'] }}</td>
                            <td>{{ $mv['reason'] }}</td>
                            <td>{{ $mv['balance'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if(!empty($data['notes']))
        <div class="section">
            <div class="section-title">OBSERVACIONES</div>
            <div style="background: #fffbeb; padding: 10px; border: 1px solid #fef3c7; font-style: italic;">
                "{{ $data['notes'] }}"
            </div>
        </div>
    @endif

    <div class="footer">
        Hotel Wimpy - Sistema de Gestión Forense de Datos<br>
        Este documento es un extracto fidedigno de los registros del sistema a la fecha de generación.
    </div>
</body>
</html>

