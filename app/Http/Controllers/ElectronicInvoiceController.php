<?php

namespace App\Http\Controllers;

use App\Http\Requests\ElectronicInvoice\ElectronicInvoiceFilterRequest;
use App\Http\Requests\StoreElectronicInvoiceRequest;
use App\Models\Customer;
use App\Models\DianDocumentType;
use App\Models\DianOperationType;
use App\Models\DianPaymentForm;
use App\Models\DianPaymentMethod;
use App\Models\ElectronicInvoice;
use App\Models\Service;
use App\Services\ElectronicInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ElectronicInvoiceController extends Controller
{
    public function __construct(
        private ElectronicInvoiceService $invoiceService
    ) {}

    // TODO: Adaptar este método cuando se implementen las reservas
    // La facturación electrónica se generará desde las reservas, no desde ventas
    // public function generate(Reservation $reservation) { ... }

    public function create(): View
    {
        $customers = Customer::with('taxProfile')
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
            ->get();
        $services = Service::active()->with(['unitMeasure', 'standardCode', 'tribute'])->orderBy('name')->get();
        $documentTypes = DianDocumentType::orderBy('name')->get();
        $operationTypes = DianOperationType::orderBy('name')->get();
        $paymentMethods = DianPaymentMethod::orderBy('name')->get();
        $paymentForms = DianPaymentForm::orderBy('name')->get();

        // Get tax catalogs for customer creation/edition modals
        $identificationDocuments = \App\Models\DianIdentificationDocument::query()->orderBy('id')->get();
        $legalOrganizations = \App\Models\DianLegalOrganization::query()->orderBy('id')->get();
        $tributes = \App\Models\DianCustomerTribute::query()->orderBy('id')->get();
        $municipalities = \App\Models\DianMunicipality::query()
            ->orderBy('department')
            ->orderBy('name')
            ->get();

        return view('electronic-invoices.create', [
            'customers' => $customers,
            'services' => $services,
            'documentTypes' => $documentTypes,
            'operationTypes' => $operationTypes,
            'paymentMethods' => $paymentMethods,
            'paymentForms' => $paymentForms,
            'identificationDocuments' => $identificationDocuments,
            'legalOrganizations' => $legalOrganizations,
            'tributes' => $tributes,
            'municipalities' => $municipalities,
        ]);
    }

    public function store(StoreElectronicInvoiceRequest $request): RedirectResponse
    {
        try {
            $invoice = $this->invoiceService->createFromForm($request->validated());
            
            return Redirect::route('electronic-invoices.show', $invoice)
                ->with('success', 'Factura electrónica creada y enviada exitosamente.');
        } catch (\Exception $e) {
            return Redirect::back()
                ->withInput()
                ->withErrors(['error' => 'Error al crear la factura: ' . $e->getMessage()]);
        }
    }

    public function index(ElectronicInvoiceFilterRequest $request)
    {
        $filters = $request->validated();

        $query = ElectronicInvoice::with(['customer.taxProfile', 'documentType', 'operationType', 'paymentMethod', 'paymentForm'])
            ->orderBy('created_at', 'desc');

        // Filtro por número de documento
        if (!empty($filters['filter_number'] ?? null)) {
            $query->where('document', 'like', '%' . $filters['filter_number'] . '%');
        }

        // Filtro por código de referencia
        if (!empty($filters['filter_reference_code'] ?? null)) {
            $query->where('reference_code', 'like', '%' . $filters['filter_reference_code'] . '%');
        }

        // Filtro por estado
        if (!empty($filters['filter_status'] ?? null)) {
            $statusMap = [
                '1' => 'accepted',
                '0' => 'pending',
            ];
            $status = $statusMap[$filters['filter_status']] ?? $filters['filter_status'];
            if (in_array($status, ['pending', 'sent', 'accepted', 'rejected', 'cancelled'])) {
                $query->where('status', $status);
            }
        }

        // Filtro por identificación del cliente
        if (!empty($filters['filter_identification'] ?? null)) {
            $query->whereHas('customer.taxProfile', function ($q) use ($filters) {
                $q->where('identification', 'like', '%' . $filters['filter_identification'] . '%');
            });
        }

        // Filtro por nombre del cliente
        if (!empty($filters['filter_names'] ?? null)) {
            $query->whereHas('customer', function ($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters['filter_names'] . '%');
            });
        }

        // Filtro por prefijo (del número de documento)
        if (!empty($filters['filter_prefix'] ?? null)) {
            $query->where('document', 'like', $filters['filter_prefix'] . '%');
        }

        $invoices = $query->paginate(15)->withQueryString();

        return view('electronic-invoices.index', [
            'invoices' => $invoices,
            'filters' => $request->only([
                'filter_identification',
                'filter_names',
                'filter_number',
                'filter_prefix',
                'filter_reference_code',
                'filter_status',
            ]),
        ]);
    }

    public function show(ElectronicInvoice $electronicInvoice)
    {
        $electronicInvoice->load([
            'customer.taxProfile',
            'creditNotes.paymentMethod',
            'numberingRange',
            'documentType',
            'operationType',
            'paymentMethod',
            'paymentForm',
            'items.unitMeasure',
        ]);

        // Determinar la ruta de retorno
        $returnUrl = null;
        
        if (request()->get('return_to') === 'index') {
            // Si viene del índice, volver al índice preservando los filtros
            $queryParams = request()->except(['return_to']);
            $returnUrl = route('electronic-invoices.index', $queryParams);
        } else {
            // Intentar obtener la URL anterior
            $referer = request()->header('referer');
            
            if ($referer) {
                // Verificar si viene del listado de facturas electrónicas
                if (str_contains($referer, route('electronic-invoices.index'))) {
                    // Preservar los filtros de búsqueda si existen
                    $queryParams = parse_url($referer, PHP_URL_QUERY);
                    $returnUrl = route('electronic-invoices.index') . ($queryParams ? '?' . $queryParams : '');
                }
            }
            
            // Si no se determinó una URL de retorno, usar el listado de facturas como default
            if (!$returnUrl) {
                $returnUrl = route('electronic-invoices.index');
            }
        }

        return view('electronic-invoices.show', compact('electronicInvoice', 'returnUrl'));
    }

    public function refreshStatus(ElectronicInvoice $electronicInvoice, \App\Services\FactusApiService $factusApi)
    {
        // Intentar buscar por número de documento, CUFE, o reference_code
        $bill = null;
        $searchNumber = $electronicInvoice->document;
        
        // Si tenemos CUFE, intentar buscar por ese campo primero
        if ($electronicInvoice->cufe) {
            try {
                $bills = $factusApi->getBills(['cufe' => $electronicInvoice->cufe], 1, 1);
                $data = $bills['data']['data'] ?? $bills['data'] ?? [];
                if (is_array($data) && !empty($data) && isset($data[0])) {
                    $bill = $data[0];
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Error al buscar factura por CUFE', [
                    'cufe' => $electronicInvoice->cufe,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Si no se encontró por CUFE y tenemos número de documento, buscar por número
        if (!$bill && $searchNumber) {
            try {
                $bill = $factusApi->getBillByNumber($searchNumber);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Error al buscar factura por número', [
                    'number' => $searchNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Si aún no se encontró, intentar por reference_code
        if (!$bill && $electronicInvoice->reference_code) {
            try {
                $bills = $factusApi->getBills(['reference_code' => $electronicInvoice->reference_code], 1, 1);
                $data = $bills['data']['data'] ?? $bills['data'] ?? [];
                if (is_array($data) && !empty($data) && isset($data[0])) {
                    $bill = $data[0];
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Error al buscar factura por reference_code', [
                    'reference_code' => $electronicInvoice->reference_code,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        if (!$bill) {
            return redirect()->route('electronic-invoices.show', $electronicInvoice)
                ->with('warning', 'No se pudo encontrar la factura en Factus. Verifica que esté generada correctamente.');
        }

        // Mapear estado desde la respuesta de Factus
        $status = 'pending';
        if (isset($bill['status'])) {
            $status = strtolower($bill['status']);
        } elseif (isset($bill['cufe']) && !empty($bill['cufe'])) {
            $status = 'accepted';
        }

        $updateData = [
            'status' => $status,
        ];

        if (isset($bill['id']) && !empty($bill['id'])) {
            $updateData['factus_bill_id'] = (int) $bill['id'];
        }

        if (isset($bill['cufe']) && !empty($bill['cufe'])) {
            $updateData['cufe'] = $bill['cufe'];
        }

        if (isset($bill['qr']) && !empty($bill['qr'])) {
            $updateData['qr'] = $bill['qr'];
        }

        if (isset($bill['pdf_url']) && !empty($bill['pdf_url'])) {
            $updateData['pdf_url'] = $bill['pdf_url'];
        }

        if (isset($bill['xml_url']) && !empty($bill['xml_url'])) {
            $updateData['xml_url'] = $bill['xml_url'];
        }

        if (isset($bill['number']) && !empty($bill['number'])) {
            $updateData['document'] = $bill['number'];
        }

        $electronicInvoice->update($updateData);

        $statusMessages = [
            'accepted' => 'Factura actualizada: Estado cambiado a Aceptada',
            'rejected' => 'Factura actualizada: Estado cambiado a Rechazada',
            'sent' => 'Factura actualizada: Estado cambiado a Enviada',
            'pending' => 'Factura actualizada: Estado sigue Pendiente',
        ];

        return redirect()->route('electronic-invoices.show', $electronicInvoice)
            ->with('success', $statusMessages[$status] ?? 'Estado de la factura actualizado');
    }

    public function downloadPdf(ElectronicInvoice $electronicInvoice, \App\Services\FactusApiService $factusApi)
    {
        // Si ya tiene PDF URL guardada localmente, usar esa
        if ($electronicInvoice->pdf_url) {
            return redirect($electronicInvoice->pdf_url);
        }

        // Si no, descargar desde Factus
        $response = $factusApi->downloadPdf($electronicInvoice->document);

        if (!isset($response['pdf_base_64_encoded'])) {
            throw new \Exception('El PDF no está disponible en este momento.');
        }

        $pdfContent = base64_decode($response['pdf_base_64_encoded']);
        $fileName = ($response['file_name'] ?? $electronicInvoice->document) . '.pdf';

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    /**
     * Elimina una factura no validada de Factus API
     */
    public function destroy(ElectronicInvoice $electronicInvoice, \App\Services\ElectronicInvoiceService $invoiceService)
    {
        try {
            $invoiceService->deleteInvoice($electronicInvoice);
            
            return redirect()->route('electronic-invoices.index')
                ->with('success', 'Factura eliminada exitosamente de Factus');
                
        } catch (\Exception $e) {
            return back()->with('error', 'Error al eliminar la factura: ' . $e->getMessage());
        }
    }
}
