<?php

namespace App\Livewire\Customers;

use Livewire\Component;
use App\Models\Customer;
use App\Models\CustomerTaxProfile;
use App\Models\DianIdentificationDocument;
use App\Models\DianLegalOrganization;
use App\Models\DianCustomerTribute;
use App\Models\DianMunicipality;
use App\Models\CompanyTaxSetting;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Log;

class EditCustomerModal extends Component
{
    public bool $isOpen = false;
    public Customer $customer;
    public CustomerTaxProfile $taxProfile;
    
    public array $formData = [
        'name' => '',
        'identification' => '',
        'phone' => '',
        'email' => '',
        'address' => '',
        'requires_electronic_invoice' => false,
        'identification_document_id' => '',
        'dv' => '',
        'company' => '',
        'trade_name' => '',
        'municipality_id' => '',
        'legal_organization_id' => '',
        'tribute_id' => '',
    ];

    public array $errors = [];
    public bool $isUpdating = false;
    public string $identificationMessage = '';
    public bool $identificationExists = false;
    public bool $requiresDV = false;
    public bool $isJuridicalPerson = false;

    #[On('open-edit-customer-modal')]
    public function open(int $customerId): void
    {
        $this->customer = Customer::with(['taxProfile.identificationDocument', 'taxProfile.municipality'])->findOrFail($customerId);
        $this->taxProfile = $this->customer->taxProfile ?? new CustomerTaxProfile();
        $this->loadCustomerData();
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->customer = new Customer();
        $this->taxProfile = new CustomerTaxProfile();
        $this->formData = [
            'name' => '',
            'identification' => '',
            'phone' => '',
            'email' => '',
            'address' => '',
            'requires_electronic_invoice' => false,
            'identification_document_id' => '',
            'dv' => '',
            'company' => '',
            'trade_name' => '',
            'municipality_id' => '',
            'legal_organization_id' => '',
            'tribute_id' => '',
        ];
        $this->errors = [];
        $this->identificationMessage = '';
        $this->identificationExists = false;
        $this->requiresDV = false;
        $this->isJuridicalPerson = false;
    }

    public function isElectronicInvoiceCustomer(): bool
    {
        return $this->customer->requires_electronic_invoice ?? false;
    }

    private function loadCustomerData(): void
    {
        $this->formData = [
            'name' => $this->customer->name,
            'identification' => $this->taxProfile->identification ?? '',
            'phone' => $this->customer->phone ?? '',
            'email' => $this->customer->email ?? '',
            'address' => $this->customer->address ?? '',
            'requires_electronic_invoice' => $this->customer->requires_electronic_invoice ?? false,
            'identification_document_id' => $this->taxProfile->identification_document_id ?? '',
            'dv' => $this->taxProfile->dv ?? '',
            'company' => $this->taxProfile->company ?? '',
            'trade_name' => $this->taxProfile->trade_name ?? '',
            'municipality_id' => $this->taxProfile->municipality_id ?? '',
            'legal_organization_id' => $this->taxProfile->legal_organization_id ?? '',
            'tribute_id' => $this->taxProfile->tribute_id ?? '',
        ];

        $this->updateRequiredFields();
    }

    public function updatedFormDataIdentificationDocumentId(): void
    {
        $this->updateRequiredFields();
    }

    public function updatedFormDataName(): void
    {
        // Si es NIT y la razón social está vacía o es igual al nombre anterior, actualizarla
        if ($this->isJuridicalPerson && (empty($this->formData['company']) || $this->formData['company'] === $this->customer->name)) {
            $this->formData['company'] = $this->formData['name'];
        }
    }

    public function updatedFormDataIdentification(): void
    {
        $this->validateIdentification();
        // No calcular automáticamente el DV, permitir edición manual
    }

    private function checkIdentificationSync(): void
    {
        if (empty($this->formData['identification']) || strlen($this->formData['identification']) < 6) {
            $this->identificationExists = false;
            return;
        }

        try {
            $profile = CustomerTaxProfile::where('identification', $this->formData['identification'])
                ->where('customer_id', '!=', $this->customer->id)
                ->first();
            
            if ($profile) {
                $customer = Customer::withTrashed()->find($profile->customer_id);
                if ($customer) {
                    $this->identificationExists = true;
                    $this->identificationMessage = "Este cliente ya está registrado como: {$customer->name}";
                    return;
                }
            }
            
            $this->identificationExists = false;
        } catch (\Exception $e) {
            Log::error('Error checking identification sync: ' . $e->getMessage());
            $this->identificationExists = false;
        }
    }

    private function updateRequiredFields(): void
    {
        if (empty($this->formData['identification_document_id'])) {
            $this->requiresDV = false;
            $this->isJuridicalPerson = false;
            return;
        }

        $document = DianIdentificationDocument::find($this->formData['identification_document_id']);
        if ($document) {
            $this->requiresDV = $document->requires_dv;
            $this->isJuridicalPerson = $document->code === 'NIT';
            
            // No calcular automáticamente el DV, permitir edición manual
            // Solo limpiar el campo si no es NIT
            if (!$this->isJuridicalPerson) {
                $this->formData['dv'] = '';
            }
            
            // Autocompletar razón social con el nombre del cliente si es NIT y está vacía
            if ($this->isJuridicalPerson && empty($this->formData['company'])) {
                $this->formData['company'] = $this->formData['name'] ?? '';
            }
        }
    }

    private function calculateDV(): void
    {
        if (!$this->isJuridicalPerson || empty($this->formData['identification'])) {
            $this->formData['dv'] = '';
            return;
        }

        $nit = preg_replace('/\D/', '', $this->formData['identification']);
        if (empty($nit)) {
            $this->formData['dv'] = '';
            return;
        }

        $weights = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        $sum = 0;
        
        for ($i = 0; $i < strlen($nit); $i++) {
            $sum += intval($nit[strlen($nit) - 1 - $i]) * $weights[$i];
        }
        
        $remainder = $sum % 11;
        $this->formData['dv'] = $remainder < 2 ? (string)$remainder : (string)(11 - $remainder);
    }

    private function validateIdentification(): void
    {
        $this->errors['identification'] = null;
        $identification = trim($this->formData['identification'] ?? '');
        
        if (empty($identification)) {
            $this->errors['identification'] = 'La identificación es obligatoria.';
            return;
        }

        $digitCount = strlen(preg_replace('/\D/', '', $identification));

        if ($digitCount < 6) {
            $this->errors['identification'] = 'El número de documento debe tener mínimo 6 dígitos.';
            return;
        }

        if ($digitCount > 10) {
            $this->errors['identification'] = 'El número de documento debe tener máximo 10 dígitos.';
            return;
        }

        if (!preg_match('/^\d+$/', $identification)) {
            $this->errors['identification'] = 'El número de documento solo puede contener dígitos.';
            return;
        }
    }

    public function update(): void
    {
        $this->errors = [];
        $this->validateIdentification();
        
        if (!empty($this->errors['identification'])) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Error en identificación: ' . ($this->errors['identification'] ?? 'Error desconocido')
            ]);
            return;
        }

        if (empty($this->formData['name'])) {
            $this->errors['name'] = 'El nombre es obligatorio.';
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'El nombre es obligatorio.'
            ]);
            return;
        }

        // Check identification synchronously before updating
        $this->checkIdentificationSync();

        if ($this->identificationExists) {
            $this->errors['identification'] = 'Este cliente ya está registrado.';
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Este cliente ya está registrado: ' . $this->identificationMessage
            ]);
            return;
        }

        // Solo validar campos de facturación electrónica si está activada
        if (!empty($this->formData['requires_electronic_invoice']) && $this->formData['requires_electronic_invoice'] === true) {
            if (empty($this->formData['identification_document_id'])) {
                $this->errors['identification_document_id'] = 'El tipo de documento es obligatorio para facturación electrónica.';
            }
            if (empty($this->formData['municipality_id'])) {
                $this->errors['municipality_id'] = 'El municipio es obligatorio para facturación electrónica.';
            }
            // Validar razón social para NIT, pero permitir que esté autocompletada con el nombre
            if ($this->isJuridicalPerson) {
                if (empty($this->formData['company'])) {
                    $this->formData['company'] = $this->formData['name']; // Autocompletar con el nombre
                }
            }
            // Validar DV si es requerido
            if ($this->requiresDV && trim((string) ($this->formData['dv'] ?? '')) === '') {
                $this->errors['dv'] = 'El dígito verificador es obligatorio.';
            }
            if ($this->requiresDV && (trim((string) ($this->formData['dv'] ?? '')) !== '' && !preg_match('/^[0-9]$/', $this->formData['dv']))) {
                $this->errors['dv'] = 'El DV debe ser un solo dígito (0-9).';
            }
        }

        $allErrors = array_filter($this->errors, fn($v) => !empty($v));
        
        if (!empty($allErrors)) {
            $errorMessages = [];
            foreach ($allErrors as $key => $value) {
                $fieldName = match($key) {
                    'name' => 'Nombre',
                    'phone' => 'Teléfono',
                    'identification' => 'Identificación',
                    'identification_document_id' => 'Tipo de documento',
                    'municipality_id' => 'Municipio',
                    'company' => 'Razón social',
                    'dv' => 'Dígito Verificador',
                    default => ucfirst(str_replace('_', ' ', $key))
                };
                $errorMessages[] = "$fieldName: " . (is_array($value) ? implode(', ', $value) : $value);
            }
            
            $errorText = implode(' | ', $errorMessages);
            
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => $errorText
            ]);
            
            return;
        }

        $this->isUpdating = true;

        try {
            $requiresElectronicInvoice = $this->formData['requires_electronic_invoice'] ?? false;
            
            // Update customer
            $this->customer->update([
                'name' => mb_strtoupper($this->formData['name']),
                'phone' => $this->formData['phone'],
                'email' => !empty($this->formData['email']) ? mb_strtolower($this->formData['email']) : null,
                'address' => $this->formData['address'] ?? null,
                'is_active' => $this->customer->is_active, // Preserve status
                'requires_electronic_invoice' => $requiresElectronicInvoice,
                'identification_number' => $this->formData['identification'] ?? null,
                'identification_type_id' => $this->formData['identification_document_id'] ?? null,
            ]);

            // Update or create tax profile
            $municipalityId = $requiresElectronicInvoice
                ? ($this->formData['municipality_id'] ?? null)
                : (CompanyTaxSetting::first()?->municipality_id
                    ?? DianMunicipality::first()?->factus_id
                    ?? 149);

            $taxProfileData = [
                'identification' => $this->formData['identification'],
                'dv' => $this->formData['dv'] ?? null,
                'identification_document_id' => $requiresElectronicInvoice
                    ? ($this->formData['identification_document_id'] ?? null)
                    : 3,
                'legal_organization_id' => $requiresElectronicInvoice
                    ? ($this->formData['legal_organization_id'] ?? null)
                    : 2,
                'tribute_id' => $requiresElectronicInvoice
                    ? ($this->formData['tribute_id'] ?? null)
                    : 21,
                'municipality_id' => $municipalityId,
                'company' => $requiresElectronicInvoice && $this->isJuridicalPerson
                    ? ($this->formData['company'] ?? null)
                    : null,
                'trade_name' => $requiresElectronicInvoice
                    ? ($this->formData['trade_name'] ?? null)
                    : null,
            ];

            if ($this->customer->taxProfile) {
                $this->customer->taxProfile->update($taxProfileData);
            } else {
                $taxProfileData['customer_id'] = $this->customer->id;
                CustomerTaxProfile::create($taxProfileData);
            }

            $this->dispatch('customer-updated');
            $this->close();
            
            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Cliente actualizado exitosamente.'
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->errors = array_merge($this->errors, $e->errors());
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Error de validación: ' . implode(', ', array_map(fn($err) => is_array($err) ? implode(', ', $err) : $err, $e->errors()))
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating customer: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'formData' => $this->formData,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $this->addError('general', 'Error al actualizar el cliente: ' . $e->getMessage());
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Error al actualizar el cliente: ' . $e->getMessage()
            ]);
        } finally {
            $this->isUpdating = false;
        }
    }

    public function render()
    {
        return view('livewire.customers.edit-customer-modal', [
            'identificationDocuments' => DianIdentificationDocument::query()->orderBy('id')->get(),
            'legalOrganizations' => DianLegalOrganization::query()->orderBy('id')->get(),
            'tributes' => DianCustomerTribute::query()->orderBy('id')->get(),
            'municipalities' => DianMunicipality::query()
                ->orderBy('department')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
