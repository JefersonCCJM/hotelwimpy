<?php

namespace App\Http\Requests;

use App\Models\ElectronicCreditNote;
use App\Models\ElectronicInvoice;
use App\Models\FactusNumberingRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreElectronicCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('generate_invoices');
    }

    public function rules(): array
    {
        return [
            'numbering_range_id' => [
                'required',
                Rule::exists('factus_numbering_ranges', 'id')->where(
                    fn ($query) => $query
                        ->where('is_active', true)
                        ->where('is_expired', false)
                        ->where(function ($creditNoteQuery): void {
                            $creditNoteQuery
                                ->whereIn('document', FactusNumberingRange::creditNoteDocumentIdentifiers())
                                ->orWhereIn('document_code', FactusNumberingRange::creditNoteDocumentCodes());
                        })
                ),
            ],
            'correction_concept_code' => [
                'required',
                'integer',
                Rule::in(array_keys(ElectronicCreditNote::CORRECTION_CONCEPTS)),
            ],
            'payment_method_code' => ['required', 'exists:dian_payment_methods,code'],
            'send_email' => ['nullable', 'boolean'],
            'reference_code' => ['nullable', 'string', 'max:255', 'unique:electronic_credit_notes,reference_code'],
            'notes' => ['nullable', 'string', 'max:250'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.code_reference' => ['required', 'string', 'max:255'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.unit_measure_id' => ['nullable', 'integer'],
            'items.*.standard_code_id' => ['nullable', 'integer'],
            'items.*.tribute_id' => ['nullable', 'integer'],
            'items.*.is_excluded' => ['nullable', 'boolean'],
            'items.*.note' => ['nullable', 'string', 'max:250'],
        ];
    }

    public function messages(): array
    {
        return [
            'numbering_range_id.required' => 'Debe seleccionar un rango de numeracion para nota credito.',
            'numbering_range_id.exists' => 'El rango seleccionado no corresponde a una nota credito activa.',
            'correction_concept_code.required' => 'Debe indicar el codigo del concepto de correccion.',
            'correction_concept_code.in' => 'El codigo de correccion seleccionado no es valido para notas credito.',
            'payment_method_code.required' => 'Debe seleccionar un metodo de pago.',
            'payment_method_code.exists' => 'El metodo de pago seleccionado no es valido.',
            'reference_code.unique' => 'El codigo de referencia ya fue usado en otra nota credito.',
            'notes.max' => 'La observacion no puede exceder 250 caracteres.',
            'items.required' => 'Debe conservar al menos un item en la nota credito.',
            'items.min' => 'Debe conservar al menos un item en la nota credito.',
            'items.*.code_reference.required' => 'Cada item debe conservar su codigo de referencia.',
            'items.*.name.required' => 'Cada item debe tener nombre.',
            'items.*.quantity.required' => 'La cantidad es obligatoria en todos los items.',
            'items.*.price.required' => 'El precio es obligatorio en todos los items.',
            'items.*.tax_rate.required' => 'La tasa de impuesto es obligatoria en todos los items.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            /** @var ElectronicInvoice|null $invoice */
            $invoice = $this->route('electronicInvoice');

            if (!$invoice) {
                return;
            }

            if (!$invoice->canGenerateCreditNote()) {
                $validator->errors()->add('electronic_invoice_id', 'La factura seleccionada no puede generar una nota credito.');
            }

            foreach ($this->input('items', []) as $index => $item) {
                if (trim((string) ($item['name'] ?? '')) === '') {
                    $validator->errors()->add("items.{$index}.name", 'El nombre del item no puede estar vacio.');
                }

                if (trim((string) ($item['code_reference'] ?? '')) === '') {
                    $validator->errors()->add("items.{$index}.code_reference", 'El codigo de referencia del item es obligatorio.');
                }
            }
        });
    }
}
