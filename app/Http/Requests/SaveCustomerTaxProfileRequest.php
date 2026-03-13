<?php

namespace App\Http\Requests;

use App\Models\DianIdentificationDocument;
use App\Models\DianMunicipality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SaveCustomerTaxProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $customer = $this->route('customer');
        $currentTaxProfileId = $customer?->taxProfile?->id;
        $requiresElectronicInvoice = $this->boolean('requires_electronic_invoice');
        $identificationDocument = $requiresElectronicInvoice && $this->has('identification_document_id')
            ? DianIdentificationDocument::find($this->input('identification_document_id'))
            : null;

        $rules = [
            'requires_electronic_invoice' => ['required', 'boolean'],
            'identification_document_id' => [
                'required_if:requires_electronic_invoice,1',
                'nullable',
                'exists:dian_identification_documents,id',
            ],
            'identification' => [
                'required_if:requires_electronic_invoice,1',
                'nullable',
                'string',
                'max:20',
                Rule::unique('customer_tax_profiles', 'identification')->ignore($currentTaxProfileId),
            ],
            'dv' => ['nullable', 'string', 'max:1'],
            'legal_organization_id' => ['nullable', 'exists:dian_legal_organizations,id'],
            'company' => ['nullable', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'names' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'tribute_id' => ['nullable', 'exists:dian_customer_tributes,id'],
            'municipality_id' => [
                'required_if:requires_electronic_invoice,1',
                'nullable',
                function (string $attribute, $value, $fail): void {
                    if ($value && !DianMunicipality::where('factus_id', $value)->exists()) {
                        $fail('El municipio seleccionado no es válido.');
                    }
                },
            ],
        ];

        if ($requiresElectronicInvoice && $identificationDocument?->requires_dv) {
            $rules['dv'] = ['required', 'string', 'size:1'];
        }

        if ($requiresElectronicInvoice && $identificationDocument?->code === 'NIT') {
            $rules['company'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (!$this->boolean('requires_electronic_invoice')) {
                return;
            }

            $document = $this->has('identification_document_id')
                ? DianIdentificationDocument::find($this->input('identification_document_id'))
                : null;

            if (!$document || $document->code !== 'NIT') {
                return;
            }

            $identification = preg_replace('/\D+/', '', (string) $this->input('identification'));
            $dv = $this->input('dv');

            if ($identification === '' || $dv === null || $dv === '') {
                return;
            }

            $expectedDv = $this->calculateNitDv($identification);
            if ((int) $dv !== $expectedDv) {
                Log::warning('DV ingresado manualmente no coincide con el calculo local, se conserva el valor proporcionado.', [
                    'identification' => $identification,
                    'provided_dv' => (string) $dv,
                    'calculated_dv' => (string) $expectedDv,
                    'customer_id' => $this->route('customer')?->id,
                ]);
            }
        });
    }

    private function calculateNitDv(string $nit): int
    {
        $weights = [41, 37, 33, 29, 25, 23, 19, 17, 13, 11, 7, 3, 1];
        $sum = 0;
        $nitReversed = strrev($nit);
        $length = strlen($nitReversed);

        for ($i = 0; $i < $length && $i < 13; $i++) {
            $sum += ((int) $nitReversed[$i]) * $weights[$i];
        }

        $mod = $sum % 11;

        return $mod < 2 ? $mod : 11 - $mod;
    }
}
