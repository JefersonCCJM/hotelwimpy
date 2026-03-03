<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte - {{ $entityTypeLabel }}</title>
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
        table.table { width: 100%; border-collapse: collapse; }
        table.table th, table.table td { border-bottom: 1px solid #e5e7eb; padding: 6px 5px; font-size: 10px; }
        table.table th { background: #f3f4f6; text-transform: uppercase; letter-spacing: .04em; color: #374151; text-align: left; font-size: 9px; }
        .text-right { text-align: right; }
        .footer { margin-top: 12px; padding-top: 8px; border-top: 1px solid #e5e7eb; text-align: center; color: #6b7280; font-size: 9px; }
    </style>
</head>
<body>
    @php
        $startDate = !empty($reportData['start_date']) ? \Illuminate\Support\Carbon::parse($reportData['start_date']) : null;
        $endDate = !empty($reportData['end_date']) ? \Illuminate\Support\Carbon::parse($reportData['end_date']) : null;
        $summary = collect($reportData['summary'] ?? [])->filter(fn($value) => is_numeric($value));
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
                    <p class="doc-title">Reporte de {{ $entityTypeLabel }}</p>
                    <p class="doc-subtitle">
                        Periodo:
                        {{ $startDate ? $startDate->translatedFormat('d/m/Y') : 'N/A' }}
                        -
                        {{ $endDate ? $endDate->translatedFormat('d/m/Y') : 'N/A' }}
                    </p>
                </td>
                <td class="meta-right">
                    <p>Generado: {{ now()->translatedFormat('d/m/Y H:i:s') }}</p>
                    @if(!empty($groupBy))
                        <p>Agrupado por: {{ $groupBy }}</p>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    @if($summary->isNotEmpty())
        <div class="section">
            <div class="section-title">Resumen</div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Concepto</th>
                        <th class="text-right">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($summary as $key => $value)
                        @php
                            $isMoney =
                                str_contains($key, 'revenue') ||
                                str_contains($key, 'amount') ||
                                str_contains($key, 'sales') ||
                                str_contains($key, 'cash') ||
                                str_contains($key, 'transfer') ||
                                str_contains($key, 'debt') ||
                                str_contains($key, 'deposit') ||
                                str_contains($key, 'pending');
                        @endphp
                        <tr>
                            <td>{{ app(\App\Services\ReportService::class)->translateSummaryKey($key) }}</td>
                            <td class="text-right">
                                @if($isMoney)
                                    ${{ number_format((float) $value, 2, ',', '.') }}
                                @else
                                    {{ number_format((float) $value, 0, ',', '.') }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if(!empty($reportData['grouped']))
        <div class="section">
            <div class="section-title">Datos Agrupados</div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Cantidad</th>
                        @if($entityType === 'sales' && isset($groupBy) && $groupBy === 'receptionist')
                            <th class="text-right">Efectivo</th>
                            <th class="text-right">Transferencia</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($reportData['grouped'] as $item)
                        <tr>
                            <td>{{ $item['name'] ?? $item['id'] ?? 'N/D' }}</td>
                            <td class="text-right">
                                @if(isset($item['total']) || isset($item['total_amount']) || isset($item['total_sales']))
                                    ${{ number_format((float) ($item['total'] ?? $item['total_amount'] ?? $item['total_sales'] ?? 0), 2, ',', '.') }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="text-right">{{ $item['count'] ?? $item['sales_count'] ?? '-' }}</td>
                            @if($entityType === 'sales' && isset($groupBy) && $groupBy === 'receptionist')
                                <td class="text-right">${{ number_format((float) ($item['cash'] ?? 0), 2, ',', '.') }}</td>
                                <td class="text-right">${{ number_format((float) ($item['transfer'] ?? 0), 2, ',', '.') }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($entityType === 'receptionists' && !empty($reportData['grouped']))
        <div class="section">
            <div class="section-title">Detalle por Recepcionista</div>
            <table class="table">
                <thead>
                    <tr>
                        <th>Recepcionista</th>
                        <th class="text-right">Total Ventas</th>
                        <th class="text-right">Cantidad</th>
                        <th class="text-right">Efectivo</th>
                        <th class="text-right">Transferencia</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reportData['grouped'] as $receptionist)
                        <tr>
                            <td>{{ $receptionist['name'] }}</td>
                            <td class="text-right">${{ number_format((float) ($receptionist['total_sales'] ?? 0), 2, ',', '.') }}</td>
                            <td class="text-right">{{ $receptionist['sales_count'] ?? 0 }}</td>
                            <td class="text-right">${{ number_format((float) ($receptionist['cash'] ?? 0), 2, ',', '.') }}</td>
                            <td class="text-right">${{ number_format((float) ($receptionist['transfer'] ?? 0), 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="footer">
        Hotel Wimpy - Sistema de Gestion
    </div>
</body>
</html>
