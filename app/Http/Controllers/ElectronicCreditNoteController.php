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
                ->with('error', 'Solo las facturas aceptadas pueden generar nota credito.');
        }

        $electronicInvoice->load([
            'customer.taxProfile',
            'items.unitMeasure',
            'paymentMethod',
            'creditNotes',
        ]);

        $numberingRanges = FactusNumberingRange::query()
            ->valid()
            ->whereIn('document', ['Nota Credito', 'Nota Crédito', 'Nota CrÃ©dito'])
            ->orderBy('prefix')
            ->get();

        $paymentMethods = DianPaymentMethod::orderBy('name')->get();

        return view('electronic-credit-notes.create', [
            'electronicInvoice' => $electronicInvoice,
            'numberingRanges' => $numberingRanges,
            'paymentMethods' => $paymentMethods,
            'correctionConcepts' => ElectronicCreditNote::correctionConceptOptions(),
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
                ->with('success', 'Nota credito creada y validada exitosamente.');
        } catch (\Exception $exception) {
            return back()
                ->withInput()
                ->withErrors(['error' => 'Error al crear la nota credito: ' . $exception->getMessage()]);
        }
    }

    public function show(ElectronicCreditNote $electronicCreditNote): View
    {
        if (
            $electronicCreditNote->canRetryWithFactus()
            || ($electronicCreditNote->isAccepted() && $electronicCreditNote->validated_at === null)
        ) {
            try {
                $electronicCreditNote = $this->creditNoteService->syncStatusFromFactus($electronicCreditNote);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

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

    public function verify(ElectronicCreditNote $electronicCreditNote): RedirectResponse
    {
        try {
            $previousStatus = $electronicCreditNote->status;
            $creditNote = $this->creditNoteService->verifyWithFactus($electronicCreditNote);
            $creditNote->refresh();

            $message = match ($creditNote->status) {
                'accepted' => $previousStatus === 'accepted'
                    ? 'La nota credito ya estaba aceptada en Factus. Se sincronizo el estado actual.'
                    : 'Estado actualizado desde Factus: la nota credito quedo aceptada.',
                'pending' => 'Estado sincronizado con Factus: la nota credito sigue pendiente.',
                'rejected' => 'Estado sincronizado con Factus: la nota credito sigue rechazada.',
                'cancelled' => 'Estado sincronizado con Factus: la nota credito quedo cancelada.',
                default => 'Estado sincronizado con Factus.',
            };

            return redirect()
                ->route('electronic-credit-notes.show', $creditNote)
                ->with('success', $message);
        } catch (\Exception $exception) {
            return redirect()
                ->route('electronic-credit-notes.show', $electronicCreditNote)
                ->withErrors(['error' => 'Error al verificar la nota credito: ' . $exception->getMessage()]);
        }
    }

    public function cleanup(ElectronicCreditNote $electronicCreditNote): RedirectResponse
    {
        try {
            $creditNote = $this->creditNoteService->cleanupPendingInFactus($electronicCreditNote);

            return redirect()
                ->route('electronic-invoices.show', $creditNote->electronicInvoice)
                ->with('success', 'La nota credito pendiente fue eliminada en Factus y quedo cancelada localmente. Ahora puedes crear una nueva.');
        } catch (\Exception $exception) {
            return redirect()
                ->route('electronic-credit-notes.show', $electronicCreditNote)
                ->withErrors(['error' => 'Error al limpiar la nota credito en Factus: ' . $exception->getMessage()]);
        }
    }

    public function downloadPdf(ElectronicCreditNote $electronicCreditNote, FactusApiService $factusApi): Response
    {
        if (blank($electronicCreditNote->document)) {
            abort(404, 'La nota credito no tiene numero disponible para descargar el PDF.');
        }

        $response = $factusApi->downloadCreditNotePdf($electronicCreditNote->document);

        if (!isset($response['pdf_base_64_encoded'])) {
            throw new \RuntimeException('El PDF de la nota credito no esta disponible en este momento.');
        }

        $pdfContent = base64_decode($response['pdf_base_64_encoded']);
        $fileName = ($response['file_name'] ?? $electronicCreditNote->document) . '.pdf';

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }
}
