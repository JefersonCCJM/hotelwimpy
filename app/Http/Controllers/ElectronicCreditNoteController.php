<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreElectronicCreditNoteRequest;
use App\Models\DianPaymentMethod;
use App\Models\ElectronicCreditNote;
use App\Models\ElectronicInvoice;
use App\Models\FactusNumberingRange;
use App\Services\ElectronicCreditNoteService;
use App\Services\FactusApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ElectronicCreditNoteController extends Controller
{
    public function __construct(
        private ElectronicCreditNoteService $creditNoteService,
    ) {}

    public function create(ElectronicInvoice $electronicInvoice): View|RedirectResponse
    {
        if (!$electronicInvoice->canGenerateCreditNote()) {
            return redirect()
                ->route('electronic-invoices.show', $electronicInvoice)
                ->with('error', 'Solo las facturas aceptadas pueden generar nota crédito.');
        }

        $electronicInvoice->load([
            'customer.taxProfile',
            'items.unitMeasure',
            'paymentMethod',
            'creditNotes',
        ]);

        $numberingRanges = FactusNumberingRange::query()
            ->valid()
            ->where('document', 'Nota Crédito')
            ->orderBy('prefix')
            ->get();

        $paymentMethods = DianPaymentMethod::orderBy('name')->get();

        $correctionConcepts = [
            ['code' => 1, 'label' => 'Código 1'],
            ['code' => 2, 'label' => 'Código 2'],
            ['code' => 3, 'label' => 'Código 3'],
            ['code' => 4, 'label' => 'Código 4'],
        ];

        return view('electronic-credit-notes.create', [
            'electronicInvoice' => $electronicInvoice,
            'numberingRanges' => $numberingRanges,
            'paymentMethods' => $paymentMethods,
            'correctionConcepts' => $correctionConcepts,
            'defaultItems' => $electronicInvoice->items->map(fn ($item): array => [
                'code_reference' => $item->code_reference,
                'name' => $item->name,
                'note' => null,
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->price,
                'tax_rate' => (float) $item->tax_rate,
                'unit_measure_id' => $item->unit_measure_id,
                'standard_code_id' => $item->standard_code_id,
                'tribute_id' => $item->tribute_id,
                'is_excluded' => (bool) $item->is_excluded,
            ])->values()->toArray(),
        ]);
    }

    public function store(StoreElectronicCreditNoteRequest $request, ElectronicInvoice $electronicInvoice): RedirectResponse
    {
        try {
            $creditNote = $this->creditNoteService->createFromInvoice($electronicInvoice, $request->validated());

            return redirect()
                ->route('electronic-credit-notes.show', $creditNote)
                ->with('success', 'Nota crédito creada y validada exitosamente.');
        } catch (\Exception $exception) {
            return back()
                ->withInput()
                ->withErrors(['error' => 'Error al crear la nota crédito: ' . $exception->getMessage()]);
        }
    }

    public function show(ElectronicCreditNote $electronicCreditNote): View
    {
        $electronicCreditNote->load([
            'electronicInvoice.customer.taxProfile',
            'customer.taxProfile',
            'numberingRange',
            'paymentMethod',
            'items',
        ]);

        return view('electronic-credit-notes.show', [
            'electronicCreditNote' => $electronicCreditNote,
            'returnUrl' => route('electronic-invoices.show', $electronicCreditNote->electronicInvoice),
        ]);
    }

    public function downloadPdf(ElectronicCreditNote $electronicCreditNote, FactusApiService $factusApi): Response
    {
        if (blank($electronicCreditNote->document)) {
            abort(404, 'La nota crédito no tiene número disponible para descargar el PDF.');
        }

        $response = $factusApi->downloadCreditNotePdf($electronicCreditNote->document);

        if (!isset($response['pdf_base_64_encoded'])) {
            throw new \RuntimeException('El PDF de la nota crédito no está disponible en este momento.');
        }

        $pdfContent = base64_decode($response['pdf_base_64_encoded']);
        $fileName = ($response['file_name'] ?? $electronicCreditNote->document) . '.pdf';

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }
}
