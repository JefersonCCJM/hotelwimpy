<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->name ? trim(mb_strtoupper($this->name)) : null,
            'identification' => $this->identification ? trim($this->identification) : null,
            'phone' => $this->phone ? trim($this->phone) : null,
            'email' => $this->email ? trim(mb_strtolower($this->email)) : null,
            'company' => $this->company ? trim(mb_strtoupper($this->company)) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s]+$/'],
            'identification' => [
                'required',
                'string',
                'max:11',
                'regex:/^\d+$/',
                Rule::unique('customer_tax_profiles', 'identification'),
            ],
            'phone' => ['required', 'string', 'max:10', 'regex:/^\d+$/'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')],
            'address' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'requires_electronic_invoice' => ['sometimes', 'boolean'],

            // Perfil fiscal (solo cuando se habilita factura electrónica)
            'identification_document_id' => [
                'required_if:requires_electronic_invoice,1',
                'nullable',
                'exists:dian_identification_documents,id',
            ],
            'dv' => [
                'nullable',
                'string',
                'max:1',
                'regex:/^[0-9]$/',
            ],
            'legal_organization_id' => ['nullable', 'exists:dian_legal_organizations,id'],
            'company' => [
                'nullable',
                'string',
                'max:255',
            ],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'municipality_id' => [
                'required_if:requires_electronic_invoice,1',
                'nullable',
                Rule::exists('dian_municipalities', 'factus_id'),
            ],
            'tribute_id' => ['nullable', 'exists:dian_customer_tributes,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede exceder 255 caracteres.',
            'name.regex' => 'El nombre solo puede contener letras y espacios.',
            'identification.required' => 'El documento de identidad es obligatorio.',
            'identification.max' => 'El documento no puede exceder 11 números.',
            'identification.regex' => 'El documento solo puede contener números.',
            'identification.unique' => 'Este documento de identidad ya está registrado.',
            'phone.required' => 'El teléfono es obligatorio.',
            'phone.max' => 'El teléfono no puede exceder 10 números.',
            'phone.regex' => 'El teléfono solo puede contener números.',
            'email.email' => 'El formato del email no es válido.',
            'email.max' => 'El email no puede exceder 255 caracteres.',
            'email.unique' => 'Este email ya está registrado.',
            'address.max' => 'La dirección no puede exceder 500 caracteres.',
            'identification_document_id.required_if' => 'El tipo de documento es obligatorio cuando se requiere facturación electrónica.',
            'identification_document_id.exists' => 'El tipo de documento seleccionado no es válido.',
            'dv.max' => 'El dígito verificador debe ser un solo número.',
            'dv.regex' => 'El dígito verificador solo puede contener números.',
            'company.required_if' => 'La razón social es obligatoria cuando se requiere facturación electrónica.',
            'company.max' => 'La razón social no puede exceder 255 caracteres.',
            'trade_name.max' => 'El nombre comercial no puede exceder 255 caracteres.',
            'municipality_id.required_if' => 'El municipio es obligatorio cuando se requiere facturación electrónica.',
            'municipality_id.exists' => 'El municipio seleccionado no es válido.',
            'legal_organization_id.exists' => 'El tipo de organización legal seleccionado no es válido.',
            'tribute_id.exists' => 'El régimen tributario seleccionado no es válido.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validate DV is required when document type requires it
            if ($this->requires_electronic_invoice && $this->identification_document_id) {
                $document = \App\Models\DianIdentificationDocument::find($this->identification_document_id);
                if ($document && $document->requires_dv) {
                    if (blank($this->dv)) {
                        $validator->errors()->add('dv', 'El dígito verificador es obligatorio para este tipo de documento.');
                    }
                }
            }

            // Validate company is required for NIT
            if ($this->requires_electronic_invoice && $this->identification_document_id) {
                $document = \App\Models\DianIdentificationDocument::find($this->identification_document_id);
                if ($document && $document->code === 'NIT') {
                    if (empty($this->company)) {
                        $validator->errors()->add('company', 'La razón social es obligatoria para NIT.');
                    }
                }
            }
        });
    }
}
