@extends('layouts.app')

@section('title', 'Nota Crédito #' . $electronicCreditNote->document)
@section('header', 'Nota Crédito')

@php
    $factusErrors = data_get($electronicCreditNote->response_dian, 'data.credit_note.errors', []);
    $dianVerificationUrl = $electronicCreditNote->dian_verification_url;
    $referencedBillNumber = $electronicCreditNote->referenced_bill_number;
@endphp

@section('content')
<div class="space-y-4 sm:space-y-6">
    @if(session('success'))
        <div class="bg-emerald-50 border-l-4 border-emerald-500 p-4 rounded-lg">
            <p class="text-sm font-semibold text-emerald-800">{{ session('success') }}</p>
        </div>
    @endif

    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
            <div>
                <div class="flex items-center space-x-3">
                    <div class="h-12 w-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                        <i class="fas fa-file-invoice-dollar text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Nota Crédito #{{ $electronicCreditNote->document }}</h1>
                        <p class="text-sm text-gray-500">Referenciada a la factura {{ $electronicCreditNote->electronicInvoice->document }}</p>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap gap-3">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $electronicCreditNote->isAccepted() ? 'bg-emerald-50 text-emerald-700' : ($electronicCreditNote->isRejected() ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700') }}">
                        {{ $electronicCreditNote->getStatusLabel() }}
                    </span>
                    @if($electronicCreditNote->cude)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-700 font-mono">
                            CUDE {{ \Illuminate\Support\Str::limit($electronicCreditNote->cude, 16, '...') }}
                        </span>
                    @endif
                </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
                <a href="{{ route('electronic-credit-notes.download-pdf', $electronicCreditNote) }}"
                   class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl border-2 border-red-600 bg-red-600 text-white text-sm font-semibold hover:bg-red-700 hover:border-red-700">
                    <i class="fas fa-file-pdf mr-2"></i>
                    Descargar PDF
                </a>

                @if($dianVerificationUrl)
                    <a href="{{ $dianVerificationUrl }}"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl border-2 border-blue-600 bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 hover:border-blue-700">
                        <i class="fas fa-external-link-alt mr-2"></i>
                        Verificar en DIAN
                    </a>
                @endif

                <a href="{{ $returnUrl }}"
                   class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl border-2 border-emerald-600 bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 hover:border-emerald-700">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Volver a la Factura
                </a>
            </div>
        </div>
    </div>

    @if(!empty($factusErrors))
        <div class="bg-amber-50 border-l-4 border-amber-500 p-4 rounded-lg">
            <p class="text-sm font-semibold text-amber-800">Factus devolvió observaciones para esta nota crédito.</p>
            <ul class="mt-2 text-sm text-amber-700 list-disc list-inside space-y-1">
                @foreach($factusErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
        <div class="lg:col-span-2 space-y-4 sm:space-y-6">
            <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
                <h2 class="text-lg font-bold text-gray-900 mb-5">Detalle del Documento</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6 text-sm">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Número</p>
                        <p class="mt-1 font-semibold text-gray-900">{{ $electronicCreditNote->document }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Código de referencia</p>
                        <p class="mt-1 font-semibold text-gray-900">{{ $electronicCreditNote->reference_code }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Factura referenciada</p>
                        <p class="mt-1 font-semibold text-gray-900">
                            <a href="{{ route('electronic-invoices.show', $electronicCreditNote->electronicInvoice) }}" class="text-blue-600 hover:text-blue-800">
                                {{ $electronicCreditNote->electronicInvoice->document }}
                            </a>
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Número en Factus de la factura referenciada</p>
                        <p class="mt-1 font-semibold text-gray-900">{{ $referencedBillNumber ?: 'No informado por Factus' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Concepto de corrección</p>
                        <p class="mt-1 font-semibold text-gray-900">Código {{ $electronicCreditNote->correction_concept_code }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Método de pago</p>
                        <p class="mt-1 font-semibold text-gray-900">{{ $electronicCreditNote->paymentMethod->name ?? $electronicCreditNote->payment_method_code }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Fecha validación</p>
                        <p class="mt-1 font-semibold text-gray-900">{{ $electronicCreditNote->validated_at?->format('d/m/Y H:i:s') ?? 'Pendiente' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">ID Factus</p>
                        <p class="mt-1 font-semibold text-gray-900">{{ $electronicCreditNote->factus_credit_note_id ?: 'Pendiente' }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Observación</p>
                        <p class="mt-1 text-gray-900">{{ $electronicCreditNote->notes ?: 'Sin observación' }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
                <div class="px-4 sm:px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-bold text-gray-900">Items de la Nota Crédito</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Código</th>
                                <th class="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Descripción</th>
                                <th class="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Cantidad</th>
                                <th class="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Precio</th>
                                <th class="px-4 sm:px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">IVA</th>
                                <th class="px-4 sm:px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Total</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach($electronicCreditNote->items as $item)
                                <tr>
                                    <td class="px-4 sm:px-6 py-4 text-sm font-mono text-gray-700">{{ $item->code_reference }}</td>
                                    <td class="px-4 sm:px-6 py-4 text-sm text-gray-900">
                                        <div class="font-semibold">{{ $item->name }}</div>
                                        @if($item->note)
                                            <div class="text-xs text-gray-500 mt-1">{{ $item->note }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 sm:px-6 py-4 text-sm text-gray-900">{{ number_format($item->quantity, 3) }}</td>
                                    <td class="px-4 sm:px-6 py-4 text-sm text-gray-900">${{ number_format($item->price, 2) }}</td>
                                    <td class="px-4 sm:px-6 py-4 text-sm text-gray-900">{{ number_format($item->tax_rate, 2) }}%</td>
                                    <td class="px-4 sm:px-6 py-4 text-sm font-semibold text-right text-gray-900">${{ number_format($item->total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="space-y-4 sm:space-y-6">
            <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
                <h2 class="text-lg font-bold text-gray-900 mb-5">Resumen</h2>
                <div class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-600">Base gravable</span>
                        <span class="font-semibold text-gray-900">${{ number_format($electronicCreditNote->gross_value, 2) }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-600">Impuestos</span>
                        <span class="font-semibold text-gray-900">${{ number_format($electronicCreditNote->tax_amount, 2) }}</span>
                    </div>
                    <div class="flex items-center justify-between pt-3 border-t border-gray-200">
                        <span class="font-bold text-gray-900">Total</span>
                        <span class="font-bold text-emerald-600">${{ number_format($electronicCreditNote->total, 2) }}</span>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
                <h2 class="text-lg font-bold text-gray-900 mb-5">Cliente</h2>
                <div class="space-y-2 text-sm text-gray-900">
                    <p class="font-semibold">{{ $electronicCreditNote->customer->name }}</p>
                    @if($electronicCreditNote->customer->taxProfile?->identification)
                        <p>{{ $electronicCreditNote->customer->taxProfile->identification }}</p>
                    @endif
                    @if($electronicCreditNote->customer->email)
                        <p>{{ $electronicCreditNote->customer->email }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
