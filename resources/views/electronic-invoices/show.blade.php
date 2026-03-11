@extends('layouts.app')

@section('title', 'Factura Electrónica #' . $electronicInvoice->document)
@section('header', 'Factura Electrónica')

@section('content')
@php($invoiceObservation = $electronicInvoice->notes ?: data_get($electronicInvoice->payload_sent, 'observation'))
<div class="space-y-4 sm:space-y-6">
    @if(session('success'))
        <div class="bg-emerald-50 border-l-4 border-emerald-500 p-4 rounded-lg">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-check-circle text-emerald-500"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-semibold text-emerald-800">{{ session('success') }}</p>
                </div>
            </div>
        </div>
    @endif

    @if(session('error'))
        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-circle text-red-500"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-semibold text-red-800">{{ session('error') }}</p>
                </div>
            </div>
        </div>
    @endif

    @if(session('warning'))
        <div class="bg-amber-50 border-l-4 border-amber-500 p-4 rounded-lg">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-triangle text-amber-500"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-semibold text-amber-800">{{ session('warning') }}</p>
                </div>
            </div>
        </div>
    @endif
    <!-- Header -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center space-x-3 sm:space-x-4">
                <div class="h-12 w-12 sm:h-14 sm:w-14 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-file-invoice-dollar text-lg sm:text-xl"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3 mb-2">
                        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 truncate">
                            Factura Electrónica #{{ $electronicInvoice->document }}
                        </h1>
                        @if($electronicInvoice->isAccepted())
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700">
                                <i class="fas fa-check-circle mr-1.5"></i>
                                Aceptada
                            </span>
                        @elseif($electronicInvoice->isRejected())
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-red-50 text-red-700">
                                <i class="fas fa-times-circle mr-1.5"></i>
                                Rechazada
                            </span>
                        @else
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700">
                                <i class="fas fa-clock mr-1.5"></i>
                                {{ ucfirst($electronicInvoice->status) }}
                            </span>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-3 sm:gap-4 text-xs sm:text-sm text-gray-500">
                        @if($electronicInvoice->cufe)
                            <div class="flex items-center space-x-1.5">
                                <i class="fas fa-key"></i>
                                <span class="font-mono">{{ substr($electronicInvoice->cufe, 0, 20) }}...</span>
                            </div>
                        @endif
                        <span class="hidden sm:inline text-gray-300">•</span>
                        <div class="flex items-center space-x-1.5">
                            <i class="fas fa-calendar-alt"></i>
                            <span>{{ $electronicInvoice->validated_at ? $electronicInvoice->validated_at->format('d/m/Y H:i') : $electronicInvoice->created_at->format('d/m/Y H:i') }}</span>
                        </div>
                        <span class="hidden sm:inline text-gray-300">•</span>
                        <div class="flex items-center space-x-1.5">
                            <i class="fas fa-dollar-sign"></i>
                            <span class="font-semibold text-gray-900">${{ number_format($electronicInvoice->total, 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
                @if($electronicInvoice->pdf_url)
                    <a href="{{ $electronicInvoice->pdf_url }}"
                       target="_blank"
                       class="inline-flex items-center justify-center px-4 sm:px-5 py-2.5 rounded-xl border-2 border-red-600 bg-red-600 text-white text-sm font-semibold hover:bg-red-700 hover:border-red-700 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 shadow-sm hover:shadow-md">
                        <i class="fas fa-file-pdf mr-2"></i>
                        <span>PDF</span>
                    </a>
                @else
                    <a href="{{ route('electronic-invoices.download-pdf', $electronicInvoice) }}"
                       class="inline-flex items-center justify-center px-4 sm:px-5 py-2.5 rounded-xl border-2 border-red-600 bg-red-600 text-white text-sm font-semibold hover:bg-red-700 hover:border-red-700 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 shadow-sm hover:shadow-md">
                        <i class="fas fa-download mr-2"></i>
                        <span>Descargar PDF</span>
                    </a>
                @endif

                @if(!$electronicInvoice->isAccepted())
                    <form action="{{ route('electronic-invoices.refresh-status', $electronicInvoice) }}" method="POST" class="inline">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center justify-center px-4 sm:px-5 py-2.5 rounded-xl border-2 border-blue-600 bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 hover:border-blue-700 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 shadow-sm hover:shadow-md"
                                title="Actualizar estado desde Factus">
                            <i class="fas fa-sync-alt mr-2"></i>
                            <span>Actualizar Estado</span>
                        </button>
                    </form>
                @endif

                <a href="{{ $returnUrl ?? route('electronic-invoices.index') }}"
                   class="inline-flex items-center justify-center px-4 sm:px-5 py-2.5 rounded-xl border-2 border-emerald-600 bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 hover:border-emerald-700 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 shadow-sm hover:shadow-md">
                    <i class="fas fa-arrow-left mr-2"></i>
                    <span>Volver</span>
                </a>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
        <!-- Contenido Principal -->
        <div class="lg:col-span-2 space-y-4 sm:space-y-6">
            <!-- Información de la Factura -->
            <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
                <div class="flex items-center space-x-3 mb-4 sm:mb-6">
                    <div class="p-2.5 rounded-xl bg-blue-50 text-blue-600">
                        <i class="fas fa-info-circle text-lg"></i>
                    </div>
                    <h2 class="text-lg sm:text-xl font-bold text-gray-900">Información de la Factura</h2>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Número de Factura
                        </label>
                        <div class="flex items-center space-x-2 text-sm text-gray-900">
                            <i class="fas fa-hashtag text-gray-400"></i>
                            <span class="font-mono font-semibold">{{ $electronicInvoice->document }}</span>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Estado DIAN
                        </label>
                        <div>
                            @if($electronicInvoice->isAccepted())
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700">
                                    <i class="fas fa-check-circle mr-1.5"></i>
                                    Aceptada
                                </span>
                            @elseif($electronicInvoice->isRejected())
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-red-50 text-red-700">
                                    <i class="fas fa-times-circle mr-1.5"></i>
                                    Rechazada
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700">
                                    <i class="fas fa-clock mr-1.5"></i>
                                    {{ ucfirst($electronicInvoice->status) }}
                                </span>
                            @endif
                        </div>
                    </div>

                    @if($electronicInvoice->cufe)
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            CUFE
                        </label>
                        <div class="flex items-center space-x-2 text-sm text-gray-900">
                            <i class="fas fa-key text-gray-400"></i>
                            <span class="font-mono break-all">{{ $electronicInvoice->cufe }}</span>
                        </div>
                    </div>
                    @endif

                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Fecha de Validación
                        </label>
                        <div class="flex items-center space-x-2 text-sm text-gray-900">
                            <i class="fas fa-calendar-check text-gray-400"></i>
                            <span>{{ $electronicInvoice->validated_at ? $electronicInvoice->validated_at->format('d/m/Y H:i:s') : 'Pendiente' }}</span>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Tipo de Documento
                        </label>
                        <div class="text-sm text-gray-900">
                            {{ $electronicInvoice->documentType->name }} ({{ $electronicInvoice->documentType->code }})
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Tipo de Operación
                        </label>
                        <div class="text-sm text-gray-900">
                            {{ $electronicInvoice->operationType->name }} ({{ $electronicInvoice->operationType->code }})
                        </div>
                    </div>

                    @if($invoiceObservation)
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Observaciones
                        </label>
                        <div class="text-sm text-gray-900 whitespace-pre-line">
                            {{ $invoiceObservation }}
                        </div>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Items de la Factura -->
            <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
                <div class="px-4 sm:px-6 py-4 border-b border-gray-100">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="p-2 rounded-xl bg-emerald-50 text-emerald-600">
                                <i class="fas fa-boxes text-lg"></i>
                            </div>
                            <div>
                                <h2 class="text-lg sm:text-xl font-bold text-gray-900">Items de la Factura</h2>
                                <p class="text-xs text-gray-500 mt-0.5">{{ $electronicInvoice->items->count() }} item(s)</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabla Desktop -->
                <div class="hidden lg:block overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                    Producto
                                </th>
                                <th class="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                    Cantidad
                                </th>
                                <th class="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                    Precio Unitario
                                </th>
                                <th class="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                    IVA
                                </th>
                                <th class="px-4 sm:px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                    Total
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach($electronicInvoice->items as $item)
                            <tr class="hover:bg-gray-50 transition-colors duration-150">
                                <td class="px-4 sm:px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="h-10 w-10 rounded-xl bg-gray-100 flex items-center justify-center mr-3 flex-shrink-0">
                                            <i class="fas fa-box text-gray-600 text-sm"></i>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm font-semibold text-gray-900 truncate">{{ $item->name }}</div>
                                            <div class="text-xs text-gray-500 font-mono mt-0.5">{{ $item->code_reference }}</div>
                                            @if($item->unitMeasure)
                                                <div class="text-xs text-gray-400 mt-0.5">{{ $item->unitMeasure->name }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold">
                                        {{ number_format($item->quantity, 2) }}
                                    </span>
                                </td>
                                <td class="px-4 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    ${{ number_format($item->price, 2) }}
                                </td>
                                <td class="px-4 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <div class="text-xs text-gray-500">{{ number_format($item->tax_rate, 2) }}%</div>
                                    <div class="font-semibold">${{ number_format($item->tax_amount, 2) }}</div>
                                </td>
                                <td class="px-4 sm:px-6 py-4 whitespace-nowrap text-right">
                                    <span class="text-sm font-bold text-emerald-600">
                                        ${{ number_format($item->total, 2) }}
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Cards Mobile/Tablet -->
                <div class="lg:hidden divide-y divide-gray-100">
                    @foreach($electronicInvoice->items as $item)
                    <div class="p-4 hover:bg-gray-50 transition-colors duration-150">
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex items-center space-x-3 flex-1 min-w-0">
                                <div class="h-10 w-10 rounded-xl bg-gray-100 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-box text-gray-600 text-sm"></i>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-semibold text-gray-900 truncate">{{ $item->name }}</div>
                                    <div class="text-xs text-gray-500 font-mono mt-0.5">{{ $item->code_reference }}</div>
                                    @if($item->unitMeasure)
                                        <div class="text-xs text-gray-400 mt-0.5">{{ $item->unitMeasure->name }}</div>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Cantidad</p>
                                <p class="text-sm font-semibold text-gray-900">{{ number_format($item->quantity, 2) }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Precio Unit.</p>
                                <p class="text-sm font-semibold text-gray-900">${{ number_format($item->price, 2) }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">IVA ({{ number_format($item->tax_rate, 2) }}%)</p>
                                <p class="text-sm font-semibold text-gray-900">${{ number_format($item->tax_amount, 2) }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Total</p>
                                <p class="text-sm font-bold text-emerald-600">${{ number_format($item->total, 2) }}</p>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            @if($electronicInvoice->qr)
            <!-- Código QR -->
            <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
                <div class="flex items-center space-x-3 mb-4">
                    <div class="p-2.5 rounded-xl bg-purple-50 text-purple-600">
                        <i class="fas fa-qrcode text-lg"></i>
                    </div>
                    <h2 class="text-lg sm:text-xl font-bold text-gray-900">Código QR</h2>
                </div>
                <div class="flex justify-center">
                    @if(str_starts_with($electronicInvoice->qr, 'data:image/png;base64,'))
                        <!-- Si es una imagen base64, mostrar directamente -->
                        <img src="{{ $electronicInvoice->qr }}"
                             alt="QR Code"
                             class="max-w-xs w-full h-auto border border-gray-200 rounded-lg p-4">
                    @else
                        <!-- Si es una URL, generar imagen QR o mostrar enlace -->
                        <div class="text-center space-y-4">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data={{ urlencode($electronicInvoice->qr) }}"
                                 alt="QR Code"
                                 class="max-w-xs w-full h-auto border border-gray-200 rounded-lg p-4">
                            <div class="mt-4">
                                <a href="{{ $electronicInvoice->qr }}" 
                                   target="_blank" 
                                   class="inline-flex items-center px-4 py-2 bg-purple-600 text-white text-sm font-semibold rounded-lg hover:bg-purple-700 transition-colors">
                                    <i class="fas fa-external-link-alt mr-2"></i>
                                    Ver QR en DIAN
                                </a>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
            @endif
        </div>

        <!-- Sidebar -->
        <div class="space-y-4 sm:space-y-6">
            <!-- Resumen -->
            <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
                <div class="flex items-center space-x-3 mb-4 sm:mb-6">
                    <div class="p-2.5 rounded-xl bg-emerald-50 text-emerald-600">
                        <i class="fas fa-calculator text-lg"></i>
                    </div>
                    <h2 class="text-lg sm:text-xl font-bold text-gray-900">Resumen</h2>
                </div>

                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Subtotal</span>
                        <span class="text-sm font-semibold text-gray-900">${{ number_format($electronicInvoice->gross_value, 2) }}</span>
                    </div>

                    @if($electronicInvoice->discount_amount > 0)
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Descuento</span>
                        <span class="text-sm font-semibold text-red-600">-${{ number_format($electronicInvoice->discount_amount, 2) }}</span>
                    </div>
                    @endif

                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">IVA</span>
                        <span class="text-sm font-semibold text-gray-900">${{ number_format($electronicInvoice->tax_amount, 2) }}</span>
                    </div>

                    <div class="border-t border-gray-200 pt-3 mt-3">
                        <div class="flex justify-between items-center">
                            <span class="text-base font-bold text-gray-900">Total</span>
                            <span class="text-lg font-bold text-emerald-600">${{ number_format($electronicInvoice->total, 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Información del Cliente -->
            <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
                <div class="flex items-center space-x-3 mb-4 sm:mb-6">
                    <div class="p-2.5 rounded-xl bg-blue-50 text-blue-600">
                        <i class="fas fa-user text-lg"></i>
                    </div>
                    <h2 class="text-lg sm:text-xl font-bold text-gray-900">Cliente</h2>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">
                            Nombre
                        </label>
                        <p class="text-sm text-gray-900 font-semibold">{{ $electronicInvoice->customer->name }}</p>
                    </div>

                    @if($electronicInvoice->customer->taxProfile)
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">
                                Identificación
                            </label>
                            <p class="text-sm text-gray-900 font-mono">
                                {{ $electronicInvoice->customer->taxProfile->identification }}
                                @if($electronicInvoice->customer->taxProfile->dv)
                                    -{{ $electronicInvoice->customer->taxProfile->dv }}
                                @endif
                            </p>
                        </div>

                        @if($electronicInvoice->customer->taxProfile->company)
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">
                                    Razón Social
                                </label>
                                <p class="text-sm text-gray-900">{{ $electronicInvoice->customer->taxProfile->company }}</p>
                            </div>
                        @endif
                    @endif
                </div>
            </div>

            <!-- Métodos de Pago -->
            <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
                <div class="flex items-center space-x-3 mb-4 sm:mb-6">
                    <div class="p-2.5 rounded-xl bg-purple-50 text-purple-600">
                        <i class="fas fa-credit-card text-lg"></i>
                    </div>
                    <h2 class="text-lg sm:text-xl font-bold text-gray-900">Pago</h2>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">
                            Método de Pago
                        </label>
                        <p class="text-sm text-gray-900">{{ $electronicInvoice->paymentMethod->name }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">
                            Forma de Pago
                        </label>
                        <p class="text-sm text-gray-900">{{ $electronicInvoice->paymentForm->name }}</p>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
@endsection
