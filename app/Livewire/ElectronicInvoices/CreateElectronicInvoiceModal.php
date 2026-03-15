<?php

namespace App\Livewire\ElectronicInvoices;

use App\Models\Customer;
use App\Models\DianDocumentType;
use App\Models\DianOperationType;
use App\Models\DianPaymentForm;
use App\Models\DianPaymentMethod;
use App\Models\FactusNumberingRange;
use App\Models\Service;
use App\Services\ElectronicInvoiceService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CreateElectronicInvoiceModal extends Component
{
    public bool $isOpen = false;

    public array $formData = [
        'customer_id' => '',
        'document_type_id' => '',
        'operation_type_id' => 1,
        'payment_method_code' => '',
        'payment_form_code' => '',
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

    protected $listeners = [
        'open-create-electronic-invoice-modal' => 'open',
    ];

    public function __construct()
    {
        $this->invoiceService = app(ElectronicInvoiceService::class);
    }

    public function open(): void
    {
        $this->reset();
        $this->resetValidation();
        $this->loadData();

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
                    'has_complete_tax_profile' => true,
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
                    'unit_measure' => $service->unitMeasure->name ?? '',
                ];
            })
            ->toArray();

        $this->documentTypes = DianDocumentType::orderBy('name')
            ->get()
            ->map(fn ($type) => [
                'id' => $type->id,
                'name' => $type->name,
            ])
            ->toArray();

        $this->operationTypes = DianOperationType::orderBy('name')
            ->get()
            ->map(fn ($type) => [
                'id' => $type->id,
                'name' => $type->name,
            ])
            ->toArray();

        $this->paymentMethods = DianPaymentMethod::orderBy('code')
            ->get()
            ->map(fn ($method) => [
                'code' => $method->code,
                'name' => $method->name,
            ])
            ->toArray();

        $this->paymentForms = DianPaymentForm::orderBy('code')
            ->get()
            ->map(fn ($form) => [
                'code' => $form->code,
                'name' => $form->name,
            ])
            ->toArray();

        $this->numberingRanges = FactusNumberingRange::query()
            ->where('is_active', true)
            ->where('is_expired', false)
            ->where('document', 'Factura de Venta')
            ->when(app()->environment('production'), function ($query) {
                $query->where('prefix', '!=', 'SETP');
            }, function ($query) {
                $query->where('prefix', 'SETP');
            })
            ->orderBy('prefix')
            ->get()
            ->map(fn ($range) => [
                'id' => $range->id,
                'document' => $range->document,
                'prefix' => $range->prefix,
                'range_from' => $range->range_from,
                'range_to' => $range->range_to,
                'current' => $range->current,
                'description' => $range->prefix . ' (' . $range->range_from . ' - ' . $range->range_to . ')',
            ])
            ->toArray();
    }

    public function addItem(): void
    {
        $this->items[] = [
            'name' => 'Servicio de hotel',
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
        if (preg_match('/^items\.(\d+)\./', $key, $matches)) {
            $this->updateItem((int) $matches[1]);
        }
    }

    public function updateItem($index): void
    {
        if (!isset($this->items[$index])) {
            return;
        }

        $item = &$this->items[$index];
        $item['subtotal'] = $item['quantity'] * $item['price'];
        $item['tax'] = $item['subtotal'] * ($item['tax_rate'] / 100);
        $item['total'] = $item['subtotal'] + $item['tax'];
    }

    public function calculateTotals(): void
    {
        foreach (array_keys($this->items) as $index) {
            $this->updateItem($index);
        }
    }

    public function getTotals(): array
    {
        return [
            'subtotal' => array_sum(array_column($this->items, 'subtotal')),
            'tax' => array_sum(array_column($this->items, 'tax')),
            'total' => array_sum(array_column($this->items, 'total')),
        ];
    }

    #[Validate([
        'formData.customer_id' => 'required|exists:customers,id',
        'formData.document_type_id' => 'required|exists:dian_document_types,id',
        'formData.operation_type_id' => 'required|exists:dian_operation_types,id',
        'formData.payment_method_code' => 'required|exists:dian_payment_methods,code',
        'formData.payment_form_code' => 'required|exists:dian_payment_forms,code',
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
            $this->validate();

            foreach ($this->items as $index => $item) {
                if (empty(trim($item['name']))) {
                    $this->dispatch('notify', [
                        'type' => 'error',
                        'message' => 'El nombre del servicio #' . ($index + 1) . ' es obligatorio y no puede estar vacío.',
                    ]);

                    return;
                }
            }

            if (empty($this->formData['operation_type_id'])) {
                $this->formData['operation_type_id'] = 1;
            }

            $this->calculateTotals();
            $totals = $this->getTotals();

            $numberingRange = collect($this->numberingRanges)
                ->firstWhere('id', $this->formData['numbering_range_id']);

            if (!$numberingRange) {
                throw new \Exception('Rango de numeración no encontrado');
            }

            $invoiceData = [
                'customer_id' => $this->formData['customer_id'],
                'document_type_id' => $this->formData['document_type_id'],
                'operation_type_id' => $this->formData['operation_type_id'],
                'payment_method_code' => (string) $this->formData['payment_method_code'],
                'payment_form_code' => (string) $this->formData['payment_form_code'],
                'numbering_range_id' => $numberingRange['id'],
                'notes' => $this->formData['notes'] ?? null,
                'items' => $this->items,
                'totals' => $totals,
            ];

            $invoice = $this->invoiceService->createFromForm($invoiceData);

            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Factura electrónica creada exitosamente. Total: $'
                    . number_format($totals['total'], 2)
                    . ' Documento: '
                    . ($invoice->document ?? 'Pendiente'),
            ]);

            $this->dispatch('invoice-created');
            $this->close();
        } catch (\Exception $e) {
            Log::error('Error creating electronic invoice: ' . $e->getMessage());

            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Error al crear la factura: ' . $e->getMessage(),
            ]);
        } finally {
            $this->isCreating = false;
        }
    }

    public function render()
    {
        return view('livewire.electronic-invoices.create-electronic-invoice-modal', [
            'totals' => $this->getTotals(),
        ]);
    }
}
