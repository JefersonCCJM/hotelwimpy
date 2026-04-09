<?php

namespace App\Livewire\Customers;

use App\Models\CompanyTaxSetting;
use App\Models\Customer;
use App\Models\CustomerTaxProfile;
use App\Models\DianCustomerTribute;
use App\Models\DianIdentificationDocument;
use App\Models\DianLegalOrganization;
use App\Models\DianMunicipality;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

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
        return (bool) ($this->formData['requires_electronic_invoice'] ?? false);
    }

    private function loadCustomerData(): void
    {
        $this->formData = [
            'name' => $this->customer->name,
            'identification' => $this->taxProfile->identification ?? $this->customer->identification_number ?? '',
            'phone' => $this->customer->phone ?? '',
            'email' => $this->customer->email ?? '',
            'address' => $this->customer->address ?? '',
            'requires_electronic_invoice' => $this->customer->requires_electronic_invoice ?? false,
            'identification_document_id' => $this->taxProfile->identification_document_id ?? $this->customer->identification_type_id ?? '',
            'dv' => $this->taxProfile->dv ?? '',
            'company' => $this->taxProfile->company ?? '',
            'trade_name' => $this->taxProfile->trade_name ?? '',
            'municipality_id' => $this->taxProfile->municipality_id ?? '',
            'legal_organization_id' => $this->taxProfile->legal_organization_id ?? '',
            'tribute_id' => $this->taxProfile->tribute_id ?? '',
        ];

        $this->updateRequiredFields();
    }

    public function updatedFormDataRequiresElectronicInvoice(bool $value): void
    {
        if ($value) {
            $this->formData['identification'] = $this->formData['identification']
                ?: ($this->customer->identification_number ?? '');
            $this->formData['identification_document_id'] = $this->formData['identification_document_id']
                ?: ($this->customer->identification_type_id ?? '');
            $this->formData['legal_organization_id'] = $this->formData['legal_organization_id'] ?: 2;
            $this->formData['tribute_id'] = $this->formData['tribute_id'] ?: 21;
        }

        $this->updateRequiredFields();
    }

    public function toggleElectronicInvoice(): void
    {
        $nextValue = !($this->formData['requires_electronic_invoice'] ?? false);
        $this->formData['requires_electronic_invoice'] = $nextValue;
        $this->updatedFormDataRequiresElectronicInvoice($nextValue);
    }

    public function updatedFormDataIdentificationDocumentId(): void
    {
        $this->updateRequiredFields();
    }

    public function updatedFormDataName(): void
    {
        if ($this->isJuridicalPerson && (empty($this->formData['company']) || $this->formData['company'] === $this->customer->name)) {
            $this->formData['company'] = $this->formData['name'];
        }
    }

    public function updatedFormDataIdentification(): void
    {
        $this->validateIdentification();
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
                    $this->identificationMessage = "Este cliente ya esta registrado como: {$customer->name}";
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
        if (!$document) {
            $this->requiresDV = false;
            $this->isJuridicalPerson = false;
            return;
        }

        $this->requiresDV = (bool) $document->requires_dv;
        $this->isJuridicalPerson = $document->code === 'NIT';

        if (!$this->isJuridicalPerson) {
            $this->formData['dv'] = '';
        }

        if ($this->isJuridicalPerson && empty($this->formData['company'])) {
            $this->formData['company'] = $this->formData['name'] ?? '';
        }
    }

    private function validateIdentification(): void
    {
        $this->errors['identification'] = null;
        $identification = trim($this->formData['identification'] ?? '');

        if ($identification === '') {
            $this->errors['identification'] = 'La identificacion es obligatoria.';
            return;
        }

        $digitCount = strlen(preg_replace('/\D/', '', $identification));

        if ($digitCount < 6) {
            $this->errors['identification'] = 'El numero de documento debe tener minimo 6 digitos.';
            return;
        }

        if ($digitCount > 10) {
            $this->errors['identification'] = 'El numero de documento debe tener maximo 10 digitos.';
            return;
        }

        if (!preg_match('/^\d+$/', $identification)) {
            $this->errors['identification'] = 'El numero de documento solo puede contener digitos.';
        }
    }

    public function update(): void
    {
        $this->errors = [];
        $this->validateIdentification();

        if (!empty($this->errors['identification'])) {
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Error en identificacion: ' . ($this->errors['identification'] ?? 'Error desconocido'),
            ]);
            return;
        }

        if (empty($this->formData['name'])) {
            $this->errors['name'] = 'El nombre es obligatorio.';
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'El nombre es obligatorio.',
            ]);
            return;
        }

        $this->checkIdentificationSync();

        if ($this->identificationExists) {
            $this->errors['identification'] = 'Este cliente ya esta registrado.';
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Este cliente ya esta registrado: ' . $this->identificationMessage,
            ]);
            return;
        }

        $requiresElectronicInvoice = (bool) ($this->formData['requires_electronic_invoice'] ?? false);

        if ($requiresElectronicInvoice) {
            if (empty($this->formData['identification_document_id'])) {
                $this->errors['identification_document_id'] = 'El tipo de documento es obligatorio para facturacion electronica.';
            }

            if (empty(trim((string) ($this->formData['address'] ?? '')))) {
                $this->errors['address'] = 'La direccion es obligatoria para facturacion electronica.';
            }

            $email = trim((string) ($this->formData['email'] ?? ''));
            if ($email === '') {
                $this->errors['email'] = 'El correo electronico es obligatorio para facturacion electronica.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->errors['email'] = 'El correo electronico no es valido.';
            }

            if (empty($this->formData['legal_organization_id'])) {
                $this->errors['legal_organization_id'] = 'La organizacion legal es obligatoria para facturacion electronica.';
            }

            if (empty($this->formData['tribute_id'])) {
                $this->errors['tribute_id'] = 'El tipo de tributo es obligatorio para facturacion electronica.';
            }

            if (empty($this->formData['municipality_id'])) {
                $this->errors['municipality_id'] = 'El municipio es obligatorio para facturacion electronica.';
            }

            if ($this->isJuridicalPerson && empty($this->formData['company'])) {
                $this->formData['company'] = $this->formData['name'];
            }

            if ($this->requiresDV && trim((string) ($this->formData['dv'] ?? '')) === '') {
                $this->errors['dv'] = 'El digito verificador es obligatorio.';
            }

            if ($this->requiresDV && trim((string) ($this->formData['dv'] ?? '')) !== '' && !preg_match('/^[0-9]$/', (string) $this->formData['dv'])) {
                $this->errors['dv'] = 'El DV debe ser un solo digito (0-9).';
            }
        }

        $allErrors = array_filter($this->errors, fn ($value) => !empty($value));

        if (!empty($allErrors)) {
            $errorMessages = [];
            foreach ($allErrors as $key => $value) {
                $fieldName = match ($key) {
                    'name' => 'Nombre',
                    'phone' => 'Telefono',
                    'email' => 'Correo electronico',
                    'address' => 'Direccion',
                    'identification' => 'Identificacion',
                    'identification_document_id' => 'Tipo de documento',
                    'legal_organization_id' => 'Organizacion legal',
                    'tribute_id' => 'Tipo de tributo',
                    'municipality_id' => 'Municipio',
                    'company' => 'Razon social',
                    'dv' => 'Digito verificador',
                    default => ucfirst(str_replace('_', ' ', $key)),
                };

                $errorMessages[] = $fieldName . ': ' . (is_array($value) ? implode(', ', $value) : $value);
            }

            $this->dispatch('notify', [
                'type' => 'error',
                'message' => implode(' | ', $errorMessages),
            ]);

            return;
        }

        $this->isUpdating = true;

        try {
            $identificationDocumentId = $this->formData['identification_document_id']
                ?: $this->customer->identification_type_id
                ?: 3;

            $this->customer->update([
                'name' => mb_strtoupper($this->formData['name']),
                'phone' => $this->formData['phone'],
                'email' => !empty($this->formData['email']) ? mb_strtolower($this->formData['email']) : null,
                'address' => $this->formData['address'] ?? null,
                'is_active' => $this->customer->is_active,
                'requires_electronic_invoice' => $requiresElectronicInvoice,
                'identification_number' => $this->formData['identification'] ?? null,
                'identification_type_id' => $identificationDocumentId,
            ]);

            $municipalityId = $requiresElectronicInvoice
                ? ($this->formData['municipality_id'] ?? null)
                : (CompanyTaxSetting::first()?->municipality_id
                    ?? DianMunicipality::first()?->factus_id
                    ?? 149);

            $taxProfileData = [
                'identification' => $this->formData['identification'],
                'dv' => $this->formData['dv'] ?? null,
                'identification_document_id' => $identificationDocumentId,
                'legal_organization_id' => $requiresElectronicInvoice
                    ? ($this->formData['legal_organization_id'] ?? null)
                    : 2,
                'tribute_id' => $requiresElectronicInvoice
                    ? ($this->formData['tribute_id'] ?? null)
                    : 21,
                'municipality_id' => $municipalityId,
                'names' => $this->formData['name'] ?? null,
                'address' => $this->formData['address'] ?? null,
                'email' => !empty($this->formData['email']) ? mb_strtolower($this->formData['email']) : null,
                'phone' => $this->formData['phone'] ?? null,
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
                'message' => 'Cliente actualizado exitosamente.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->errors = array_merge($this->errors, $e->errors());
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Error de validacion: ' . implode(', ', array_map(fn ($err) => is_array($err) ? implode(', ', $err) : $err, $e->errors())),
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating customer: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'formData' => $this->formData,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->addError('general', 'Error al actualizar el cliente: ' . $e->getMessage());
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Error al actualizar el cliente: ' . $e->getMessage(),
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
