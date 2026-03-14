@extends('layouts.app')

@section('title', 'Crear Nota Crédito')
@section('header', 'Nueva Nota Crédito')

@php
    $formItems = array_values(old('items', $defaultItems));
    $recoverableCreditNotes = $electronicInvoice->creditNotes->whereIn('status', ['pending', 'rejected']);
    $acceptedAnulationCreditNotes = $electronicInvoice->creditNotes
        ->where('status', 'accepted')
        ->where('correction_concept_code', 2);
    $hasOpenCreditNoteAttempts = $recoverableCreditNotes->isNotEmpty();
    $hasAcceptedAnulationCreditNotes = $acceptedAnulationCreditNotes->isNotEmpty();
@endphp

@section('content')
<div class="space-y-4 sm:space-y-6" x-data="{
    items: @js($formItems),
    lineSubtotal(item) {
        return (parseFloat(item.quantity) || 0) * (parseFloat(item.price) || 0);
    },
    lineTax(item) {
        return this.lineSubtotal(item) * ((parseFloat(item.tax_rate) || 0) / 100);
    },
    lineTotal(item) {
        return this.lineSubtotal(item) + this.lineTax(item);
    },
    currency(value) {
        return new Intl.NumberFormat('es-CO', {
            style: 'currency',
            currency: 'COP',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(value || 0);
    },
    removeItem(index) {
        this.items.splice(index, 1);
    },
    get subtotal() {
        return this.items.reduce((sum, item) => sum + this.lineSubtotal(item), 0);
    },
    get tax() {
        return this.items.reduce((sum, item) => sum + this.lineTax(item), 0);
    },
    get total() {
        return this.items.reduce((sum, item) => sum + this.lineTotal(item), 0);
    },
}">
    @if($errors->any())
        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-circle text-red-500"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-semibold text-red-800">No se pudo crear la nota crédito.</p>
                    <ul class="mt-2 text-sm text-red-700 list-disc list-inside space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    @if($recoverableCreditNotes->isNotEmpty())
        <div class="bg-amber-50 border-l-4 border-amber-500 p-4 rounded-lg">
            <p class="text-sm font-semibold text-amber-800">Esta factura ya tiene notas crédito pendientes o rechazadas. Debes verificarlas o limpiarlas antes de crear una nueva.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach($recoverableCreditNotes as $recoverableCreditNote)
                    <a href="{{ route('electronic-credit-notes.show', $recoverableCreditNote) }}"
                       class="inline-flex items-center px-3 py-2 rounded-lg bg-white text-amber-800 text-sm font-semibold border border-amber-200 hover:bg-amber-100">
                        {{ $recoverableCreditNote->document ?: $recoverableCreditNote->reference_code }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if($acceptedAnulationCreditNotes->isNotEmpty())
        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
            <p class="text-sm font-semibold text-red-800">
                Esta factura ya tiene una nota crédito de anulación aceptada. No puedes emitir otra anulación total para la misma factura.
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach($acceptedAnulationCreditNotes as $acceptedCreditNote)
                    <a href="{{ route('electronic-credit-notes.show', $acceptedCreditNote) }}"
                       class="inline-flex items-center px-3 py-2 rounded-lg bg-white text-red-800 text-sm font-semibold border border-red-200 hover:bg-red-100">
                        {{ $acceptedCreditNote->document ?: $acceptedCreditNote->reference_code }}
                    </a>
                @endforeach
            </div>
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
                        <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Crear Nota Crédito</h1>
                        <p class="text-sm text-gray-500">Flujo referenciado a la factura electrónica {{ $electronicInvoice->document }}</p>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                    <div class="rounded-xl bg-gray-50 border border-gray-200 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Factura</p>
                        <p class="mt-1 font-semibold text-gray-900">{{ $electronicInvoice->document }}</p>
                    </div>
                    <div class="rounded-xl bg-gray-50 border border-gray-200 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Cliente</p>
                        <p class="mt-1 font-semibold text-gray-900">{{ $electronicInvoice->customer->name }}</p>
                    </div>
                    <div class="rounded-xl bg-gray-50 border border-gray-200 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Total facturado</p>
                        <p class="mt-1 font-semibold text-gray-900">${{ number_format($electronicInvoice->total, 2) }}</p>
                    </div>
                </div>
            </div>

            <a href="{{ route('electronic-invoices.show', $electronicInvoice) }}"
               class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl border-2 border-gray-200 bg-white text-gray-700 text-sm font-semibold hover:bg-gray-50">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver a la Factura
            </a>
        </div>
    </div>

    <form method="POST" action="{{ route('electronic-credit-notes.store', $electronicInvoice) }}" class="space-y-4 sm:space-y-6">
        @csrf

        <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
            <div class="flex items-center space-x-3 mb-5">
                <div class="p-2 rounded-xl bg-blue-50 text-blue-600">
                    <i class="fas fa-sliders-h"></i>
                </div>
                <div>
                    <h2 class="text-lg font-bold text-gray-900">Parámetros de la Nota</h2>
                    <p class="text-sm text-gray-500">Este flujo usa `customization_id = 20` porque la nota referencia una factura electrónica existente.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                <div>
                    <label for="numbering_range_id" class="block text-xs font-semibold text-gray-700 mb-2">
                        Rango de numeración <span class="text-red-500">*</span>
                    </label>
                    <select id="numbering_range_id" name="numbering_range_id"
                            class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            required>
                        <option value="">Seleccione...</option>
                        @foreach($numberingRanges as $range)
                            <option value="{{ $range->id }}" @selected(old('numbering_range_id') == $range->id)>
                                {{ $range->prefix }} ({{ $range->range_from }} - {{ $range->range_to }})
                            </option>
                        @endforeach
                    </select>
                    @if($numberingRanges->isEmpty())
                        <p class="mt-2 text-xs text-amber-700">No hay rangos activos de nota crédito sincronizados desde Factus.</p>
                    @endif
                </div>

                <div>
                    <label for="correction_concept_code" class="block text-xs font-semibold text-gray-700 mb-2">
                        Código de corrección <span class="text-red-500">*</span>
                    </label>
                    <select id="correction_concept_code" name="correction_concept_code"
                            class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            required>
                        <option value="">Seleccione...</option>
                        @foreach($correctionConcepts as $concept)
                            <option value="{{ $concept['code'] }}" @selected((string) old('correction_concept_code', '2') === (string) $concept['code'])>
                                {{ $concept['label'] }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-2 text-xs text-gray-500">Verifica el código final con la tabla oficial de Factus para tu caso tributario.</p>
                </div>

                <div>
                    <label for="payment_method_code" class="block text-xs font-semibold text-gray-700 mb-2">
                        Método de pago <span class="text-red-500">*</span>
                    </label>
                    <select id="payment_method_code" name="payment_method_code"
                            class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            required>
                        <option value="">Seleccione...</option>
                        @foreach($paymentMethods as $paymentMethod)
                            <option value="{{ $paymentMethod->code }}" @selected(old('payment_method_code', $electronicInvoice->payment_method_code) == $paymentMethod->code)>
                                {{ $paymentMethod->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="reference_code" class="block text-xs font-semibold text-gray-700 mb-2">
                        Código de referencia
                    </label>
                    <input id="reference_code"
                           type="text"
                           name="reference_code"
                           value="{{ old('reference_code') }}"
                           class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           placeholder="Opcional">
                    <p class="mt-2 text-xs text-gray-500">Si lo dejas vacío, el sistema generará uno automáticamente.</p>
                </div>

                <div class="md:col-span-2 xl:col-span-3">
                    <label for="notes" class="block text-xs font-semibold text-gray-700 mb-2">
                        Observación
                    </label>
                    <textarea id="notes"
                              name="notes"
                              rows="3"
                              maxlength="250"
                              class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                              placeholder="Observación opcional para Factus">{{ old('notes') }}</textarea>
                </div>

                <div class="flex items-start md:items-center">
                    <label class="inline-flex items-center gap-3 mt-6">
                        <input type="hidden" name="send_email" value="0">
                        <input type="checkbox" name="send_email" value="1" @checked(old('send_email', '1') == '1')
                               class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="text-sm text-gray-700 font-medium">Enviar correo al cliente</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
            <div class="px-4 sm:px-6 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-gray-900">Items a acreditar</h2>
                    <p class="text-sm text-gray-500">Puedes ajustar cantidades o valores. Si un item no aplica, elimínalo de la nota.</p>
                </div>
                <span class="inline-flex items-center px-3 py-1 rounded-full bg-gray-100 text-gray-700 text-xs font-semibold">
                    Referencia Factura {{ $electronicInvoice->document }}
                </span>
            </div>

            <div class="p-4 sm:p-6 space-y-4">
                <template x-if="items.length === 0">
                    <div class="rounded-xl border border-dashed border-amber-300 bg-amber-50 px-4 py-6 text-sm text-amber-800">
                        Debes conservar al menos un item en la nota crédito.
                    </div>
                </template>

                <template x-for="(item, index) in items" :key="index">
                    <div class="rounded-xl border border-gray-200 p-4">
                        <div class="flex items-start justify-between gap-3 mb-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Item</p>
                                <p class="text-sm font-semibold text-gray-900" x-text="item.code_reference"></p>
                            </div>
                            <button type="button"
                                    @click="removeItem(index)"
                                    class="inline-flex items-center px-3 py-2 rounded-lg text-sm font-semibold text-red-700 bg-red-50 hover:bg-red-100">
                                <i class="fas fa-trash mr-2"></i>
                                Quitar
                            </button>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">
                            <div class="xl:col-span-2">
                                <label class="block text-xs font-semibold text-gray-700 mb-2">Descripción</label>
                                <input type="text"
                                       :name="`items[${index}][name]`"
                                       x-model="item.name"
                                       class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-2">Cantidad</label>
                                <input type="number"
                                       step="0.001"
                                       min="0.001"
                                       :name="`items[${index}][quantity]`"
                                       x-model="item.quantity"
                                       class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-2">Precio</label>
                                <input type="number"
                                       step="0.01"
                                       min="0"
                                       :name="`items[${index}][price]`"
                                       x-model="item.price"
                                       class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-2">IVA (%)</label>
                                <input type="number"
                                       step="0.01"
                                       min="0"
                                       max="100"
                                       :name="`items[${index}][tax_rate]`"
                                       x-model="item.tax_rate"
                                       class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>

                            <div class="md:col-span-2 xl:col-span-3">
                                <label class="block text-xs font-semibold text-gray-700 mb-2">Nota del item</label>
                                <input type="text"
                                       maxlength="250"
                                       :name="`items[${index}][note]`"
                                       x-model="item.note"
                                       class="block w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                       placeholder="Opcional">
                            </div>

                            <div class="xl:col-span-2 grid grid-cols-3 gap-3">
                                <div class="rounded-lg bg-gray-50 border border-gray-200 px-3 py-2.5">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Base</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-900" x-text="currency(lineSubtotal(item))"></p>
                                </div>
                                <div class="rounded-lg bg-gray-50 border border-gray-200 px-3 py-2.5">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Impuesto</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-900" x-text="currency(lineTax(item))"></p>
                                </div>
                                <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-2.5">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-700">Total</p>
                                    <p class="mt-1 text-sm font-semibold text-emerald-700" x-text="currency(lineTotal(item))"></p>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" :name="`items[${index}][code_reference]`" :value="item.code_reference">
                        <input type="hidden" :name="`items[${index}][unit_measure_id]`" :value="item.unit_measure_id">
                        <input type="hidden" :name="`items[${index}][standard_code_id]`" :value="item.standard_code_id">
                        <input type="hidden" :name="`items[${index}][tribute_id]`" :value="item.tribute_id">
                        <input type="hidden" :name="`items[${index}][is_excluded]`" :value="item.is_excluded ? 1 : 0">
                    </div>
                </template>
            </div>
        </div>

        <div class="bg-gray-50 rounded-xl border border-gray-200 p-4 sm:p-6">
            <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
                <div class="text-sm text-gray-600">
                    <p class="font-semibold text-gray-900">Resumen estimado</p>
                    <p class="mt-1">Los totales finales pueden cambiar ligeramente según la validación de Factus.</p>
                </div>
                <div class="w-full max-w-sm space-y-2 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-600">Base gravable</span>
                        <span class="font-semibold text-gray-900" x-text="currency(subtotal)"></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-600">Impuestos</span>
                        <span class="font-semibold text-gray-900" x-text="currency(tax)"></span>
                    </div>
                    <div class="flex items-center justify-between pt-2 border-t border-gray-300 text-base">
                        <span class="font-bold text-gray-900">Total nota crédito</span>
                        <span class="font-bold text-emerald-600" x-text="currency(total)"></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-end gap-3">
            <a href="{{ route('electronic-invoices.show', $electronicInvoice) }}"
               class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl border-2 border-gray-200 bg-white text-gray-700 text-sm font-semibold hover:bg-gray-50">
                Cancelar
            </a>
            <button type="submit"
                    class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl border-2 border-amber-600 bg-amber-600 text-white text-sm font-semibold hover:bg-amber-700 hover:border-amber-700 disabled:opacity-60 disabled:cursor-not-allowed"
                    @disabled($numberingRanges->isEmpty() || $hasOpenCreditNoteAttempts || $hasAcceptedAnulationCreditNotes)
                    :disabled="items.length === 0 || @js($hasOpenCreditNoteAttempts || $hasAcceptedAnulationCreditNotes)">
                <i class="fas fa-save mr-2"></i>
                Crear Nota Crédito
            </button>
        </div>
    </form>
</div>
@endsection
