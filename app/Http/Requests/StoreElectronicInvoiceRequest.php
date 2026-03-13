<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreElectronicInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('generate_invoices');
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'document_type_id' => ['required', 'exists:dian_document_types,id'],
            'operation_type_id' => ['required', 'exists:dian_operation_types,id'],
            'payment_method_code' => ['required', 'exists:dian_payment_methods,code'],
            'payment_form_code' => ['required', 'exists:dian_payment_forms,code'],
            'reference_code' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:250'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.service_id' => ['required', 'exists:services,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Debe seleccionar un cliente.',
            'customer_id.exists' => 'El cliente seleccionado no es válido.',
            'document_type_id.required' => 'Debe seleccionar un tipo de documento.',
            'document_type_id.exists' => 'El tipo de documento seleccionado no es válido.',
            'operation_type_id.required' => 'Debe seleccionar un tipo de operación.',
            'operation_type_id.exists' => 'El tipo de operación seleccionado no es válido.',
            'payment_method_code.required' => 'Debe seleccionar un método de pago.',
            'payment_method_code.exists' => 'El método de pago seleccionado no es válido.',
            'payment_form_code.required' => 'Debe seleccionar una forma de pago.',
            'payment_form_code.exists' => 'La forma de pago seleccionada no es válida.',
            'notes.max' => 'La observacion no puede exceder 250 caracteres.',
            'items.required' => 'Debe agregar al menos un servicio a la factura.',
            'items.min' => 'Debe agregar al menos un servicio a la factura.',
            'items.*.service_id.required' => 'Cada item debe tener un servicio asociado.',
            'items.*.service_id.exists' => 'Uno de los servicios seleccionados no es válido.',
            'items.*.quantity.required' => 'La cantidad es obligatoria.',
            'items.*.quantity.numeric' => 'La cantidad debe ser un número válido.',
            'items.*.quantity.min' => 'La cantidad debe ser mayor a 0.',
            'items.*.price.required' => 'El precio es obligatorio.',
            'items.*.price.numeric' => 'El precio debe ser un número válido.',
            'items.*.price.min' => 'El precio no puede ser negativo.',
            'items.*.tax_rate.numeric' => 'La tasa de impuesto debe ser un número válido.',
            'items.*.tax_rate.min' => 'La tasa de impuesto no puede ser negativa.',
            'items.*.tax_rate.max' => 'La tasa de impuesto no puede exceder 100%.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $customerId = $this->input('customer_id');
            
            if ($customerId) {
                $customer = Customer::with('taxProfile')->find($customerId);
                
                if (!$customer) {
                    $validator->errors()->add('customer_id', 'El cliente seleccionado no existe.');
                    return;
                }
                
                if (!$customer->hasCompleteTaxProfileData()) {
                    $missingFields = $customer->getMissingTaxProfileFields();
                    $validator->errors()->add(
                        'customer_id',
                        'El cliente no tiene perfil fiscal completo. Faltan: ' . implode(', ', $missingFields)
                    );
                }
            }
            
            // Validate that all services are active
            $items = $this->input('items', []);
            foreach ($items as $index => $item) {
                if (isset($item['service_id'])) {
                    $service = Service::find($item['service_id']);
                    if ($service && !$service->is_active) {
                        $validator->errors()->add(
                            "items.{$index}.service_id",
                            "El servicio '{$service->name}' está inactivo y no puede ser utilizado."
                        );
                    }
                }
            }
        });
    }
}
