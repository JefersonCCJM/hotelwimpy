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

class CreateCustomerModal extends Component
{
    public bool $isOpen = false;
    public string $context = 'principal'; // 'principal' o 'additional'
    public string $activeTab = 'basic'; // 'basic' o 'complete'
    // PROPIEDADES PARA CLIENTE BÁSICO
    public array $basicData = [
        'name' => '',
        'identification' => '',
        'phone' => '',
        'identification_document_id' => '',
    ];
    
    // PROPIEDADES PARA CLIENTE DIAN
    public array $dianData = [
        'name' => '',
        'identification' => '',
        'dv' => '',
        'identification_document_id' => '',
        'address' => '',
        'email' => '',
        'phone' => '',
        'country' => 'CO',
        'municipality_id' => '',
        'legal_organization_id' => '',
        'tribute_id' => '',
        'company' => '', // Campo company para personas jurídicas
    ];
    
    public array $errors = [];
    public bool $isCreatingBasic = false;
    public bool $isCreatingDian = false;
    public string $identificationMessage = '';
    public bool $identificationExists = false;
    public ?string $selectedDocumentCode = '';

    #[On('open-create-customer-modal')]
    public function open(): void
    {
        $this->reset();
        $this->resetValidation();
        $this->context = 'principal';
        $this->applyBasicDefaults();
        $this->activeTab = 'basic';
        $this->isOpen = true;
    }

    #[On('open-create-customer-modal-for-additional')]
    public function openForAdditional(): void
    {
        $this->reset();
        $this->resetValidation();
        $this->context = 'additional';
        $this->applyBasicDefaults();
        $this->activeTab = 'basic';
        $this->isOpen = true;
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }


    public function getDocumentCode(): string
    {
        $docId = $this->dianData['identification_document_id'];
        if (!$docId) {
            return '';
        }
        
        $document = DianIdentificationDocument::find($docId);
        return $document?->code ?? '';
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->activeTab = 'basic';
        $this->basicData = [
            'name' => '',
            'identification' => '',
            'phone' => '',
            'identification_document_id' => '',
        ];
        
        $this->dianData = [
            'name' => '',
            'identification' => '',
            'dv' => '',
            'identification_document_id' => '',
            'address' => '',
            'email' => '',
            'phone' => '',
            'country' => 'CO',
            'municipality_id' => '',
            'legal_organization_id' => '',
            'tribute_id' => '',
            'company' => '', // Agregar campo company al reset
        ];
        
        $this->errors = [];
        $this->identificationMessage = '';
        $this->identificationExists = false;
        $this->selectedDocumentCode = '';
        $this->applyBasicDefaults();
    }

    private function applyBasicDefaults(): void
    {
        $defaultDocument = DianIdentificationDocument::query()
            ->where('code', 'CC')
            ->first(['id', 'code']);

        if (!$defaultDocument) {
            $defaultDocument = DianIdentificationDocument::query()
                ->orderBy('id')
                ->first(['id', 'code']);
        }

        $this->basicData['identification_document_id'] = $defaultDocument?->id ?? '';
        $this->selectedDocumentCode = $defaultDocument?->code ?? '';
    }

    // MÉTODOS PARA CLIENTE BÁSICO
    public function updatedBasicDataIdentification(): void
    {
        $this->checkIdentificationExists($this->basicData['identification']);
    }
    
    public function updatedBasicDataIdentificationDocumentId(): void
    {
        // Actualizar el código del documento seleccionado
        $document = DianIdentificationDocument::find($this->basicData['identification_document_id']);
        $this->selectedDocumentCode = $document?->code ?? '';
    }
    
    // MÉTODOS PARA CLIENTE DIAN
    public function updatedDianDataIdentificationDocumentId(): void
    {
        // Actualizar el código del documento seleccionado
        $document = DianIdentificationDocument::find($this->dianData['identification_document_id']);
        $this->selectedDocumentCode = $document?->code ?? '';
        
        // Limpiar DV si no es NIT
        if ($this->selectedDocumentCode !== 'NIT') {
            $this->dianData['dv'] = '';
        }
    }
    
    public function updatedDianDataIdentification(): void
    {
        $this->checkIdentificationExists($this->dianData['identification']);
    }

    public function updatedDianDataLegalOrganizationId(): void
    {
        // Si selecciona persona jurídica, autocompletar el campo company con el nombre
        if ($this->dianData['legal_organization_id'] == 1) { // ID 1 = Persona Jurídica
            $this->dianData['company'] = $this->dianData['name'];
        } else {
            $this->dianData['company'] = ''; // Limpiar si no es jurídica
        }
    }

    private function checkIdentificationExists(string $identification): void
    {
        if (empty($identification) || strlen($identification) < 6) {
            $this->identificationExists = false;
            return;
        }

        try {
            $profile = CustomerTaxProfile::where('identification', $identification)->first();
            
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


    // VALIDACIONES SEPARADAS
    private function validateBasicCustomer(): array
    {
        $errors = [];
        
        // Validar nombre
        if (empty($this->basicData['name'])) {
            $errors['name'] = 'El nombre es obligatorio.';
        }
        
        // Validar identificación
        $identification = trim($this->basicData['identification'] ?? '');
        if (empty($identification)) {
            $errors['identification'] = 'La identificación es obligatoria.';
        } else {
            $digitCount = strlen(preg_replace('/\D/', '', $identification));
            if ($digitCount < 6) {
                $errors['identification'] = 'El número de documento debe tener mínimo 6 dígitos.';
            } elseif ($digitCount > 10) {
                $errors['identification'] = 'El número de documento debe tener máximo 10 dígitos.';
            } elseif (!preg_match('/^\d+$/', $identification)) {
                $errors['identification'] = 'El número de documento solo puede contener dígitos.';
            }
        }
        
        // Validar tipo de documento
        if (empty($this->basicData['identification_document_id'])) {
            $errors['identification_document_id'] = 'El tipo de documento es obligatorio.';
        }
        
        return $errors;
    }
    
    private function validateDianCustomer(): array
    {
        $errors = [];
        $documentCode = $this->getDocumentCode();
        
        // Validar nombre
        if (empty($this->dianData['name'])) {
            $errors['name'] = 'El nombre es obligatorio.';
        }
        
        // Validar identificación
        $identification = trim($this->dianData['identification'] ?? '');
        if (empty($identification)) {
            $errors['identification'] = 'La identificación es obligatoria.';
        } else {
            $digitCount = strlen(preg_replace('/\D/', '', $identification));
            if ($digitCount < 6) {
                $errors['identification'] = 'El número de documento debe tener mínimo 6 dígitos.';
            } elseif ($digitCount > 10) {
                $errors['identification'] = 'El número de documento debe tener máximo 10 dígitos.';
            } elseif (!preg_match('/^\d+$/', $identification)) {
                $errors['identification'] = 'El número de documento solo puede contener dígitos.';
            }
        }
        
        // Validar tipo de documento
        if (empty($this->dianData['identification_document_id'])) {
            $errors['identification_document_id'] = 'El tipo de documento es obligatorio.';
        }
        
        // Validar DV si es NIT
        if ($documentCode === 'NIT' && trim((string) ($this->dianData['dv'] ?? '')) === '') {
            $errors['dv'] = 'El dígito de verificación es obligatorio para NIT.';
        }
        
        // Validar dirección
        if (empty($this->dianData['address'])) {
            $errors['address'] = 'La dirección es obligatoria.';
        }
        
        // Validar email
        if (empty($this->dianData['email'])) {
            $errors['email'] = 'El correo electrónico es obligatorio.';
        } elseif (!filter_var($this->dianData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'El correo electrónico no es válido.';
        }
        
        // Validar municipio si es Colombia
        if ($this->dianData['country'] === 'CO' && empty($this->dianData['municipality_id'])) {
            $errors['municipality_id'] = 'El municipio es obligatorio para Colombia.';
        }
        
        // Validar organización legal
        if (empty($this->dianData['legal_organization_id'])) {
            $errors['legal_organization_id'] = 'La organización legal es obligatoria.';
        }
        
        // Validar company si es persona jurídica
        if ($this->dianData['legal_organization_id'] == 1 && empty($this->dianData['company'])) {
            $errors['company'] = 'La razón social es obligatoria para personas jurídicas.';
        }
        
        // Validar tributo
        if (empty($this->dianData['tribute_id'])) {
            $errors['tribute_id'] = 'El tipo de tributo es obligatorio.';
        }
        
        return $errors;
    }

    // MÉTODOS DE CREACIÓN SEPARADOS
    public function createBasic(): void
    {
        $this->errors = [];
        
        // Validar cliente básico
        $validationErrors = $this->validateBasicCustomer();
        
        if (!empty($validationErrors)) {
            $this->errors = $validationErrors;
            $errorMessages = [];
            foreach ($validationErrors as $key => $value) {
                $fieldName = match($key) {
                    'name' => 'Nombre',
                    'phone' => 'Teléfono',
                    'identification' => 'Identificación',
                    'identification_document_id' => 'Tipo de documento',
                    default => ucfirst(str_replace('_', ' ', $key))
                };
                $errorMessages[] = "$fieldName: " . (is_array($value) ? implode(', ', $value) : $value);
            }
            
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => implode(' | ', $errorMessages)
            ]);
            return;
        }

        // Verificar si la identificación ya existe
        $this->checkIdentificationExists($this->basicData['identification']);
        if ($this->identificationExists) {
            $this->errors['identification'] = 'Este cliente ya está registrado.';
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Este cliente ya está registrado: ' . $this->identificationMessage
            ]);
            return;
        }

        $this->isCreatingBasic = true;

        try {
            // Crear cliente básico
            $customer = Customer::create([
                'name' => mb_strtoupper(trim($this->basicData['name'])),
                'phone' => trim($this->basicData['phone']),
                'email' => null,
                'address' => null,
                'is_active' => true,
                'requires_electronic_invoice' => false,
                'identification_number' => trim($this->basicData['identification']),
                'identification_type_id' => $this->basicData['identification_document_id'],
            ]);

            // Crear perfil tributario con valores por defecto
            CustomerTaxProfile::create([
                'customer_id' => $customer->id,
                'identification' => trim($this->basicData['identification']),
                'dv' => null,
                'identification_document_id' => $this->basicData['identification_document_id'],
                'legal_organization_id' => 2, // Persona natural por defecto
                'tribute_id' => 21, // No responsable de IVA por defecto
                'municipality_id' => CompanyTaxSetting::first()?->municipality_id ?? DianMunicipality::first()?->factus_id ?? 149,
                'company' => null,
                'trade_name' => null,
            ]);

            $customerPayload = [
                'id' => $customer->id,
                'name' => $customer->name,
                'identification' => trim($this->basicData['identification']),
                'phone' => $customer->phone,
            ];

            $this->dispatch(
                'customer-created',
                customerId: $customer->id,
                customer: $customerPayload,
                customerData: $customerPayload,
                context: $this->context
            );
            $this->close();
            
            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Cliente básico creado exitosamente.'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error creating basic customer: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'basicData' => $this->basicData,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            $userMessage = $this->getUserFriendlyErrorMessage($e);
            
            $this->addError('general', $userMessage);
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => $userMessage
            ]);
        } finally {
            $this->isCreatingBasic = false;
        }
    }
    
    public function createDian(): void
    {
        $this->errors = [];
        
        // Validar cliente DIAN
        $validationErrors = $this->validateDianCustomer();
        
        if (!empty($validationErrors)) {
            $this->errors = $validationErrors;
            $errorMessages = [];
            foreach ($validationErrors as $key => $value) {
                $fieldName = match($key) {
                    'name' => 'Nombre',
                    'phone' => 'Teléfono',
                    'identification' => 'Identificación',
                    'identification_document_id' => 'Tipo de documento',
                    'dv' => 'Dígito de verificación',
                    'address' => 'Dirección',
                    'email' => 'Correo electrónico',
                    'municipality_id' => 'Municipio',
                    'legal_organization_id' => 'Organización legal',
                    'tribute_id' => 'Tipo de tributo',
                    default => ucfirst(str_replace('_', ' ', $key))
                };
                $errorMessages[] = "$fieldName: " . (is_array($value) ? implode(', ', $value) : $value);
            }
            
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => implode(' | ', $errorMessages)
            ]);
            return;
        }

        // Verificar si la identificación ya existe
        $this->checkIdentificationExists($this->dianData['identification']);
        if ($this->identificationExists) {
            $this->errors['identification'] = 'Este cliente ya está registrado.';
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Este cliente ya está registrado: ' . $this->identificationMessage
            ]);
            return;
        }

        $this->isCreatingDian = true;

        try {
            // Crear cliente DIAN
            $customer = Customer::create([
                'name' => mb_strtoupper(trim($this->dianData['name'])),
                'phone' => trim($this->dianData['phone']),
                'email' => !empty($this->dianData['email']) ? mb_strtolower(trim($this->dianData['email'])) : null,
                'address' => trim($this->dianData['address']) ?? null,
                'is_active' => true,
                'requires_electronic_invoice' => true,
                'identification_number' => trim($this->dianData['identification']),
                'identification_type_id' => $this->dianData['identification_document_id'],
            ]);

            // Crear perfil tributario completo
            CustomerTaxProfile::create([
                'customer_id' => $customer->id,
                'identification' => trim($this->dianData['identification']),
                'dv' => ($dv = trim((string) ($this->dianData['dv'] ?? ''))) !== '' ? $dv : null,
                'identification_document_id' => $this->dianData['identification_document_id'],
                'legal_organization_id' => $this->dianData['legal_organization_id'],
                'tribute_id' => $this->dianData['tribute_id'],
                'municipality_id' => $this->dianData['municipality_id'],
                'company' => !empty($this->dianData['company']) ? mb_strtoupper(trim($this->dianData['company'])) : null,
                'trade_name' => null,
            ]);

            $customerPayload = [
                'id' => $customer->id,
                'name' => $customer->name,
                'identification' => trim($this->dianData['identification']),
                'phone' => $customer->phone,
            ];

            $this->dispatch(
                'customer-created',
                customerId: $customer->id,
                customer: $customerPayload,
                customerData: $customerPayload,
                context: $this->context
            );
            $this->close();
            
            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Cliente para facturación electrónica creado exitosamente.'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error creating DIAN customer: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'dianData' => $this->dianData,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            $userMessage = $this->getUserFriendlyErrorMessage($e);
            
            $this->addError('general', $userMessage);
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => $userMessage
            ]);
        } finally {
            $this->isCreatingDian = false;
        }
    }

    /**
     * Convertir errores técnicos a mensajes amigables para el usuario
     */
    private function getUserFriendlyErrorMessage(\Exception $e): string
    {
        $message = $e->getMessage();
        
        // Error de email duplicado
        if (str_contains($message, 'customers_email_unique') || str_contains($message, 'Duplicate entry') && str_contains($message, '@')) {
            return 'Este correo electrónico ya está registrado. Por favor, usa otro correo o verifica si el cliente ya existe.';
        }
        
        // Error de identificación duplicada
        if (str_contains($message, 'identification_number') && str_contains($message, 'Duplicate entry')) {
            return 'Este número de identificación ya está registrado. Por favor, verifica si el cliente ya existe.';
        }
        
        // Error de constraint violation genérico
        if (str_contains($message, 'Integrity constraint violation')) {
            return 'Alguno de los datos proporcionados ya existe en el sistema. Por favor, verifica la información.';
        }
        
        // Error de conexión a base de datos
        if (str_contains($message, 'Connection') || str_contains($message, 'SQLSTATE[HY000]')) {
            return 'Error de conexión con la base de datos. Por favor, intenta nuevamente en unos momentos.';
        }
        
        // Error de validación de Laravel
        if (str_contains($message, 'validation')) {
            return 'Por favor, completa todos los campos obligatorios correctamente.';
        }
        
        // Mensaje genérico para otros errores
        return 'No se pudo crear el cliente. Por favor, verifica los datos e intenta nuevamente.';
    }

    public function render()
    {
        return view('livewire.customers.create-customer-modal', [
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
