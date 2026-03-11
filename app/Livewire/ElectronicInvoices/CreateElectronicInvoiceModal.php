<?php

namespace App\Livewire\ElectronicInvoices;

use Livewire\Component;
use App\Models\Customer;
use App\Models\Service;
use App\Models\DianDocumentType;
use App\Models\DianOperationType;
use App\Models\DianPaymentMethod;
use App\Models\DianPaymentForm;
use App\Services\ElectronicInvoiceService;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Illuminate\Support\Facades\Log;

class CreateElectronicInvoiceModal extends Component
{
    public bool $isOpen = false;
    
    public array $formData = [
        'customer_id' => '',
        'document_type_id' => '',
        'operation_type_id' => 1, // Valor por defecto: Estándar (ID 1)
        'payment_method_id' => '',
        'payment_form_id' => '',
        'numbering_range_id' => '',
        'customer_document' => '',
        'notes' => '',
    ];
    
    public array $items = [];
    public array $customers = [];
    public array $services = [];
    public array $documentTypes = [];
    public array $operationTypes = [];
    public array $paymentMethods = [];
    public array $paymentForms = [];
    public array $numberingRanges = [];
    public bool $isCreating = false;

    protected ElectronicInvoiceService $invoiceService;

    public function __construct()
    {
        $this->invoiceService = app(ElectronicInvoiceService::class);
    }

    protected $listeners = [
        'open-create-electronic-invoice-modal' => 'open',
    ];

    public function open(): void
    {
        $this->reset();
        $this->resetValidation();
        $this->loadData();
        
        // Initialize with one empty item if no items exist
        if (empty($this->items)) {
            $this->addItem();
        }
        
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->reset();
    }

    private function loadData(): void
    {
        $this->customers = Customer::with('taxProfile')
            ->active()
            ->where('requires_electronic_invoice', true)
            ->whereHas('taxProfile', function ($query) {
                $query->whereNotNull('identification_document_id')
                      ->whereNotNull('identification')
                      ->whereNotNull('legal_organization_id')
                      ->whereNotNull('tribute_id')
                      ->whereNotNull('municipality_id');
            })
            ->orderBy('name')
            ->get()
            ->map(function ($customer) {
                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'identification' => $customer->taxProfile->identification ?? '',
                    'email' => $customer->email ?? '',
                    'has_complete_tax_profile' => true
                ];
            })
            ->toArray();

        $this->services = Service::active()
            ->with(['unitMeasure', 'standardCode', 'tribute'])
            ->orderBy('name')
            ->get()
            ->map(function ($service) {
                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'price' => $service->price,
                    'tax_rate' => $service->tax_rate ?? 0,
                    'code' => $service->code_reference ?? '',
                    'unit_measure' => $service->unitMeasure->name ?? ''
                ];
            })
            ->toArray();

        $this->documentTypes = DianDocumentType::orderBy('name')->get()->map(function ($type) {
            return [
                'id' => $type->id,
                'name' => $type->name
            ];
        })->toArray();

        $this->operationTypes = DianOperationType::orderBy('name')->get()->map(function ($type) {
            return [
                'id' => $type->id,
                'name' => $type->name
            ];
        })->toArray();

        // Cargar métodos de pago con sus códigos
        $this->paymentMethods = DianPaymentMethod::orderBy('name')->get()->map(function ($method) {
            return [
                'id' => $method->id,
                'name' => $method->name,
                'code' => $method->code ?? ''
            ];
        })->toArray();

        // Cargar formas de pago con sus códigos
        $this->paymentForms = DianPaymentForm::orderBy('name')->get()->map(function ($form) {
            return [
                'id' => $form->id,
                'name' => $form->name,
                'code' => $form->code ?? ''
            ];
        })->toArray();

        // Cargar rangos de numeración: en producción excluir SETP (pruebas), en local solo SETP
        $this->numberingRanges = \App\Models\FactusNumberingRange::where('is_active', true)
            ->where('is_expired', false)
            ->where('document', 'Factura de Venta')
            ->when(app()->environment('production'), function ($query) {
                $query->where('prefix', '!=', 'SETP');
            }, function ($query) {
                $query->where('prefix', 'SETP');
            })
            ->orderBy('prefix')
            ->get()
            ->map(function ($range) {
                return [
                    'id' => $range->id,
                    'document' => $range->document,
                    'prefix' => $range->prefix,
                    'range_from' => $range->range_from,
                    'range_to' => $range->range_to,
                    'current' => $range->current,
                    'description' => $range->prefix . ' (' . $range->range_from . ' - ' . $range->range_to . ')',
                ];
            })->toArray();
    }

    public function addItem(): void
    {
        $this->items[] = [
            'name' => 'Servicio ' . (count($this->items) + 1),
            'quantity' => 1,
            'price' => 0,
            'tax_rate' => 0,
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
        ];
    }

    public function removeItem($index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
        $this->calculateTotals();
    }

    public function updatedItems($value, $key): void
    {
        // Extraer el índice del item (ej: "items.0.name" -> "0")
        if (preg_match('/^items\.(\d+)\./', $key, $matches)) {
            $index = (int) $matches[1];
            $this->updateItem($index);
        }
    }

    public function updateItem($index): void
    {
        if (!isset($this->items[$index])) {
            return;
        }
        
        $item = &$this->items[$index];
        
        // Calcular subtotal
        $item['subtotal'] = $item['quantity'] * $item['price'];
        
        // Calcular impuesto
        $item['tax'] = $item['subtotal'] * ($item['tax_rate'] / 100);
        
        // Calcular total
        $item['total'] = $item['subtotal'] + $item['tax'];
    }

    public function calculateTotals(): void
    {
        foreach ($this->items as $index => $item) {
            $this->updateItem($index);
        }
    }

    public function getTotals(): array
    {
        $subtotal = array_sum(array_column($this->items, 'subtotal'));
        $tax = array_sum(array_column($this->items, 'tax'));
        $total = array_sum(array_column($this->items, 'total'));

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
        ];
    }

    #[Validate([
        'formData.customer_id' => 'required|exists:customers,id',
        'formData.document_type_id' => 'required|exists:dian_document_types,id',
        'formData.operation_type_id' => 'required|exists:dian_operation_types,id',
        'formData.payment_method_id' => 'required|exists:dian_payment_methods,id',
        'formData.payment_form_id' => 'required|exists:dian_payment_forms,id',
        'formData.numbering_range_id' => 'required|exists:factus_numbering_ranges,id',
        'formData.notes' => 'nullable|string|max:250',
        'items' => 'required|array|min:1',
        'items.*.name' => 'required|string|max:255|min:1',
        'items.*.quantity' => 'required|numeric|min:0.001',
        'items.*.price' => 'required|numeric|min:0',
        'items.*.tax_rate' => 'required|numeric|min:0|max:100',
    ])]
    public function create(): void
    {
        $this->isCreating = true;

        try {
            // Validación adicional para asegurar que los nombres no estén vacíos
            foreach ($this->items as $index => $item) {
                if (empty(trim($item['name']))) {
                    $this->dispatch('notify', [
                        'type' => 'error',
                        'message' => "El nombre del servicio #{($index + 1)} es obligatorio y no puede estar vacío."
                    ]);
                    return;
                }
            }

            // Asegurar que operation_type_id tenga un valor por defecto si está vacío
            if (empty($this->formData['operation_type_id'])) {
                $this->formData['operation_type_id'] = 1; // Estándar por defecto
            }

            // Calcular totales antes de guardar
            $this->calculateTotals();
            $totals = $this->getTotals();

            // Obtener códigos de pago directamente desde los datos cargados
            $paymentMethod = collect($this->paymentMethods)->firstWhere('id', $this->formData['payment_method_id']);
            $paymentForm = collect($this->paymentForms)->firstWhere('id', $this->formData['payment_form_id']);
            $numberingRange = collect($this->numberingRanges)->firstWhere('id', $this->formData['numbering_range_id']);
            
            if (!$paymentMethod || !$paymentForm || !$numberingRange) {
                throw new \Exception('Método, forma de pago o rango de numeración no encontrado');
            }

            // Preparar datos para el servicio
            $invoiceData = [
                'customer_id' => $this->formData['customer_id'],
                'document_type_id' => $this->formData['document_type_id'],
                'operation_type_id' => $this->formData['operation_type_id'],
                'payment_method_code' => $paymentMethod['code'],
                'payment_form_code' => $paymentForm['code'],
                'numbering_range_id' => $numberingRange['id'],
                'notes' => $this->formData['notes'] ?? null,
                'items' => $this->items,
                'totals' => $totals,
            ];

            // Crear la factura electrónica usando el servicio
            $invoice = $this->invoiceService->createFromForm($invoiceData);

            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Factura electrónica creada exitosamente. Total: $' . number_format($totals['total'], 2) . 
                             ' Documento: ' . ($invoice->document ?? 'Pendiente')
            ]);
            
            // Disparar evento para actualizar la tabla
            $this->dispatch('invoice-created');
            
            $this->close();
            
        } catch (\Exception $e) {
            Log::error('Error creating electronic invoice: ' . $e->getMessage());
            
            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Error al crear la factura: ' . $e->getMessage()
            ]);
        } finally {
            $this->isCreating = false;
        }
    }

    private function generateDocumentNumber(): string
    {
        // Generar número de documento consecutivo
        $lastInvoice = \App\Models\ElectronicInvoice::latest()->first();
        $sequence = $lastInvoice ? ((int)substr($lastInvoice->document, -9)) + 1 : 1;
        
        return 'SETP' . str_pad($sequence, 9, '0', STR_PAD_LEFT);
    }

    private function generateReferenceCode(): string
    {
        return 'INV-' . date('Ymd') . '-' . strtoupper(uniqid());
    }

    public function render()
    {
        $totals = $this->getTotals();
        
        return view('livewire.electronic-invoices.create-electronic-invoice-modal', [
            'totals' => $totals,
        ]);
    }
}
