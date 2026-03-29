<?php

namespace App\Services;

use App\Exceptions\FactusApiException;
use App\Models\Customer;
use App\Models\ElectronicCreditNote;
use App\Models\ElectronicCreditNoteItem;
use App\Models\ElectronicInvoice;
use App\Models\FactusNumberingRange;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ElectronicCreditNoteService
{
    private const DEFAULT_CUSTOMIZATION_ID = 20;
    private const DEFAULT_STANDARD_CODE_ID = 1;
    private const DEFAULT_TRIBUTE_ID = 1;
    private const DEFAULT_UNIT_MEASURE_ID = 70;
    private const FACTUS_OBSERVATION_MAX_LENGTH = 250;

    public function __construct(
        private FactusApiService $apiService,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function createFromInvoice(ElectronicInvoice $invoice, array $data): ElectronicCreditNote
    {
        $invoice->loadMissing([
            'customer.taxProfile.identificationDocument',
            'customer.taxProfile.municipality',
            'items',
            'creditNotes',
        ]);

        $this->ensureInvoiceCanGenerateCreditNote($invoice);
        $this->validateCustomerTaxProfileForFactus($invoice->customer);
        $this->ensureNoOpenCreditNoteAttemptsForInvoice($invoice);
        $this->ensureNoDuplicateAcceptedAnulationCreditNote(
            $invoice,
            (int) ($data['correction_concept_code'] ?? 0)
        );

        $factusBillId = $this->resolveReferencedFactusBillId($invoice);
        $numberingRange = $this->resolveNumberingRange((int) $data['numbering_range_id']);
        $items = collect($data['items'] ?? []);
        $totals = $this->calculateTotals($items);
        $normalizedNotes = $this->normalizeObservation($data['notes'] ?? null);

        DB::beginTransaction();

        try {
            $creditNote = ElectronicCreditNote::create([
                'electronic_invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'factus_numbering_range_id' => $numberingRange->factus_id,
                'referenced_factus_bill_id' => $factusBillId,
                'correction_concept_code' => (int) $data['correction_concept_code'],
                'customization_id' => self::DEFAULT_CUSTOMIZATION_ID,
                'payment_method_code' => (string) $data['payment_method_code'],
                'send_email' => (bool) ($data['send_email'] ?? true),
                'reference_code' => $data['reference_code'] ?? $this->generateReferenceCode(),
                'document' => $this->generateDocumentNumber($numberingRange),
                'status' => 'pending',
                'gross_value' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'discount_amount' => 0,
                'surcharge_amount' => 0,
                'total' => $totals['total'],
                'notes' => $normalizedNotes,
            ]);

            foreach ($items as $item) {
                $itemData = $this->normalizeItemData($item);

                ElectronicCreditNoteItem::create([
                    'electronic_credit_note_id' => $creditNote->id,
                    'tribute_id' => $itemData['tribute_id'],
                    'standard_code_id' => $itemData['standard_code_id'],
                    'unit_measure_id' => $itemData['unit_measure_id'],
                    'code_reference' => $itemData['code_reference'],
                    'name' => $itemData['name'],
                    'note' => $itemData['note'],
                    'quantity' => $itemData['quantity'],
                    'price' => $itemData['price'],
                    'tax_rate' => $itemData['tax_rate'],
                    'tax_amount' => $itemData['tax_amount'],
                    'discount_rate' => $itemData['discount_rate'],
                    'is_excluded' => $itemData['is_excluded'],
                    'total' => $itemData['total'],
                ]);
            }

            DB::commit();

            try {
                $this->sendToFactus($creditNote);
            } catch (\Exception $exception) {
                Log::warning('Error sending credit note to Factus, keeping as pending', [
                    'credit_note_id' => $creditNote->id,
                    'electronic_invoice_id' => $invoice->id,
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            return $creditNote->fresh(['electronicInvoice', 'customer', 'items']);
        } catch (\Exception $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error('Error creating electronic credit note', [
                'electronic_invoice_id' => $invoice->id,
                'error' => $exception->getMessage(),
                'data' => $data,
            ]);

            throw $exception;
        }
    }

    public function verifyWithFactus(ElectronicCreditNote $creditNote): ElectronicCreditNote
    {
        $creditNote->loadMissing([
            'customer.taxProfile.identificationDocument',
            'customer.taxProfile.municipality',
            'items',
            'electronicInvoice',
        ]);

        if ($creditNote->isAccepted()) {
            return $creditNote->fresh(['electronicInvoice', 'customer', 'items']);
        }

        if (!$creditNote->canRetryWithFactus()) {
            throw new \RuntimeException('Esta nota credito fue cancelada y ya no puede verificarse. Crea una nueva si todavia la necesitas.');
        }

        if ($this->syncRemoteCreditNoteSnapshot($creditNote, $this->resolveSyncPayload($creditNote), [
            'flow' => 'verify_existing_remote_credit_note',
        ])) {
            return $creditNote->fresh(['electronicInvoice', 'customer', 'items']);
        }

        $this->validateCustomerTaxProfileForFactus($creditNote->customer);
        $this->sendToFactus($creditNote);

        return $creditNote->fresh(['electronicInvoice', 'customer', 'items']);
    }

    public function syncStatusFromFactus(ElectronicCreditNote $creditNote): ElectronicCreditNote
    {
        $creditNote->loadMissing([
            'customer.taxProfile.identificationDocument',
            'customer.taxProfile.municipality',
            'items',
            'electronicInvoice',
        ]);

        $this->syncRemoteCreditNoteSnapshot($creditNote, $this->resolveSyncPayload($creditNote), [
            'flow' => 'manual_status_sync',
        ]);

        return $creditNote->fresh(['electronicInvoice', 'customer', 'items']);
    }

    public function cleanupPendingInFactus(ElectronicCreditNote $creditNote): ElectronicCreditNote
    {
        $creditNote->loadMissing(['electronicInvoice', 'customer', 'items']);

        if (!$creditNote->canCleanupInFactus()) {
            throw new \RuntimeException('Solo se pueden limpiar en Factus notas credito pendientes o rechazadas con codigo de referencia.');
        }

        $cleanupResult = 'deleted';

        try {
            $response = $this->apiService->deleteCreditNoteByReference((string) $creditNote->reference_code);
        } catch (FactusApiException $exception) {
            if ($exception->getStatusCode() !== 404) {
                throw new \RuntimeException(
                    'No fue posible eliminar la nota credito pendiente en Factus: ' . $this->buildFactusErrorMessage($exception)
                );
            }

            $cleanupResult = 'not_found';
            $response = is_array($exception->getResponseBody())
                ? $exception->getResponseBody()
                : ['message' => $exception->getMessage()];
        }

        $responseDian = is_array($creditNote->response_dian) ? $creditNote->response_dian : [];
        $responseDian['_manual_cleanup'] = [
            'performed_at' => now()->toISOString(),
            'reference_code' => $creditNote->reference_code,
            'result' => $cleanupResult,
            'response' => $response,
        ];

        $creditNote->update([
            'status' => 'cancelled',
            'response_dian' => $responseDian,
        ]);

        Log::info('Nota credito pendiente limpiada manualmente en Factus', [
            'credit_note_id' => $creditNote->id,
            'reference_code' => $creditNote->reference_code,
            'result' => $cleanupResult,
        ]);

        return $creditNote->fresh(['electronicInvoice', 'customer', 'items']);
    }

    private function sendToFactus(ElectronicCreditNote $creditNote): void
    {
        $creditNote->loadMissing([
            'items',
            'electronicInvoice',
            'customer.taxProfile.identificationDocument',
            'customer.taxProfile.municipality',
        ]);

        $payload = $this->buildPayload($creditNote);

        Log::info('Payload enviado a Factus para nota credito', [
            'credit_note_id' => $creditNote->id,
            'electronic_invoice_id' => $creditNote->electronic_invoice_id,
            'payload' => $payload,
        ]);

        try {
            $response = $this->apiService->post('/v1/credit-notes/validate', $payload);

            if ($this->successResponseHasAlreadyProcessedError($response)) {
                if ($this->syncExistingProcessedCreditNoteByReference($creditNote, $payload)) {
                    return;
                }
                $this->markCreditNoteAsRejectedFromSuccessRegla90($creditNote, $payload, $response);

                return;
            }

            $this->applySuccessfulFactusCreditNoteResponse($creditNote, $payload, $response);
        } catch (FactusApiException $exception) {
            $cleanupContext = null;

            if ($this->isPendingCreditNoteConflict($exception)) {
                $cleanupContext = $this->cleanupCurrentFactusCreditNoteReference($creditNote);

                if (!empty($cleanupContext['current_reference_cleanup']['success'])) {
                    try {
                        $response = $this->apiService->post('/v1/credit-notes/validate', $payload);
                        $this->applySuccessfulFactusCreditNoteResponse($creditNote, $payload, $response, null, [
                            'cleanup_context' => $cleanupContext,
                            'flow' => 'retry_after_current_reference_cleanup',
                        ]);

                        return;
                    } catch (FactusApiException $retryException) {
                        $exception = $retryException;
                    }
                }
            }

            if ($this->isAlreadyProcessedCreditNoteError($exception)
                && $this->syncExistingProcessedCreditNoteByReference($creditNote, $payload, $cleanupContext)
            ) {
                return;
            }

            $this->persistFailedFactusCreditNoteAttempt($creditNote, $payload, $exception, $cleanupContext);

            Log::error('Error sending credit note to Factus API', [
                'credit_note_id' => $creditNote->id,
                'status_code' => $exception->getStatusCode(),
                'error_message' => $exception->getMessage(),
                'error_data' => $exception->getResponseBody(),
                'payload' => $payload,
            ]);

            throw new \Exception(
                'Error al enviar la nota credito a Factus: ' . $this->buildFactusErrorMessage($exception, $cleanupContext)
            );
        } catch (\Exception $exception) {
            Log::error('Unexpected error sending credit note to Factus', [
                'credit_note_id' => $creditNote->id,
                'error' => $exception->getMessage(),
                'payload' => $payload,
            ]);

            throw new \Exception('Error al enviar la nota credito a Factus: ' . $exception->getMessage());
        }
    }

    private function buildPayload(ElectronicCreditNote $creditNote): array
    {
        $payload = [
            'numbering_range_id' => $creditNote->factus_numbering_range_id,
            'correction_concept_code' => $creditNote->correction_concept_code,
            'customization_id' => $creditNote->customization_id,
            'bill_id' => $creditNote->referenced_factus_bill_id,
            'reference_code' => $creditNote->reference_code,
            'payment_method_code' => $creditNote->payment_method_code,
            'send_email' => $creditNote->send_email,
            'customer' => $this->buildCustomerPayload($creditNote),
            'items' => $creditNote->items->map(function (ElectronicCreditNoteItem $item): array {
                return [
                    'note' => $item->note,
                    'code_reference' => $item->code_reference,
                    'name' => trim($item->name),
                    'quantity' => (int) $item->quantity,
                    'discount_rate' => (float) $item->discount_rate,
                    'price' => (float) $item->price,
                    'tax_rate' => number_format((float) $item->tax_rate, 2, '.', ''),
                    'unit_measure_id' => (int) ($item->unit_measure_id ?: self::DEFAULT_UNIT_MEASURE_ID),
                    'standard_code_id' => (int) ($item->standard_code_id ?: self::DEFAULT_STANDARD_CODE_ID),
                    'is_excluded' => $item->is_excluded ? 1 : 0,
                    'tribute_id' => self::DEFAULT_TRIBUTE_ID,
                    'withholding_taxes' => [],
                ];
            })->values()->toArray(),
        ];

        $observation = $this->normalizeObservation($creditNote->notes);
        if ($observation !== null) {
            $payload['observation'] = $observation;
        }

        return $payload;
    }

    private function ensureInvoiceCanGenerateCreditNote(ElectronicInvoice $invoice): void
    {
        if (!$invoice->canGenerateCreditNote()) {
            throw new \InvalidArgumentException('Solo se pueden generar notas credito a partir de facturas aceptadas.');
        }
    }

    private function ensureNoOpenCreditNoteAttemptsForInvoice(ElectronicInvoice $invoice): void
    {
        $openAttempts = $invoice->creditNotes
            ->whereIn('status', ['pending', 'rejected'])
            ->values();

        if ($openAttempts->isEmpty()) {
            return;
        }

        $labels = $openAttempts
            ->map(static function (ElectronicCreditNote $creditNote): string {
                return $creditNote->document ?: $creditNote->reference_code ?: ('ID ' . $creditNote->id);
            })
            ->implode(', ');

        throw new \RuntimeException(
            'Esta factura ya tiene intentos de nota credito pendientes o rechazados (' . $labels . '). Verificalos o limpialos antes de crear una nueva.'
        );
    }

    private function ensureNoDuplicateAcceptedAnulationCreditNote(
        ElectronicInvoice $invoice,
        int $correctionConceptCode
    ): void {
        if ($correctionConceptCode !== 2) {
            return;
        }

        $acceptedAnulations = $invoice->creditNotes
            ->where('status', 'accepted')
            ->where('correction_concept_code', 2)
            ->values();

        if ($acceptedAnulations->isEmpty()) {
            return;
        }

        $labels = $acceptedAnulations
            ->map(static function (ElectronicCreditNote $creditNote): string {
                return $creditNote->document ?: $creditNote->reference_code ?: ('ID ' . $creditNote->id);
            })
            ->implode(', ');

        throw new \RuntimeException(
            'Esta factura ya tiene una nota credito de anulacion aceptada (' . $labels . '). No puedes emitir otra anulacion total para la misma factura.'
        );
    }

    private function resolveNumberingRange(int $numberingRangeId): FactusNumberingRange
    {
        $numberingRange = FactusNumberingRange::findOrFail($numberingRangeId);

        if (!$numberingRange->isValid() || !$numberingRange->isCreditNoteRange()) {
            throw new \InvalidArgumentException('El rango de numeracion seleccionado no corresponde a notas credito activas.');
        }

        return $numberingRange;
    }

    private function resolveReferencedFactusBillId(ElectronicInvoice $invoice): int
    {
        $resolvedBillId = $this->searchReferencedFactusBillId($invoice);
        if ($resolvedBillId !== null) {
            if ((int) $invoice->factus_bill_id !== $resolvedBillId) {
                $invoice->update(['factus_bill_id' => $resolvedBillId]);
            }

            return $resolvedBillId;
        }

        Log::warning('Factura aceptada localmente pero no encontrada en la cuenta actual de Factus', [
            'electronic_invoice_id' => $invoice->id,
            'stored_factus_bill_id' => $invoice->factus_bill_id,
            'stored_response_bill_id' => $this->extractBillIdFromStoredResponse($invoice->response_dian ?? []),
            'document' => $invoice->document,
            'reference_code' => $invoice->reference_code,
            'cufe' => $invoice->cufe,
        ]);

        throw new \RuntimeException(
            'No fue posible encontrar la factura seleccionada en la cuenta actual de Factus. Verifica que la factura exista en este ambiente antes de emitir la nota credito.'
        );
    }

    private function searchReferencedFactusBillId(ElectronicInvoice $invoice): ?int
    {
        foreach ($this->buildInvoiceSearchFilters($invoice) as $filters) {
            try {
                $response = $this->apiService->getBills($filters, 1, 1);
                $bill = $this->extractFirstBill($response);

                if (!empty($bill['id'])) {
                    return (int) $bill['id'];
                }
            } catch (\Exception $exception) {
                Log::warning('No se pudo resolver bill_id de Factus para factura referenciada', [
                    'electronic_invoice_id' => $invoice->id,
                    'filters' => $filters,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractFirstBill(array $response): ?array
    {
        $data = $response['data']['data'] ?? $response['data'] ?? null;

        if (is_array($data) && array_is_list($data)) {
            return $data[0] ?? null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $responseDian
     */
    private function extractBillIdFromStoredResponse(array $responseDian): ?int
    {
        foreach ([
            data_get($responseDian, 'data.bill.id'),
            data_get($responseDian, 'data.id'),
            data_get($responseDian, 'bill.id'),
            data_get($responseDian, 'id'),
        ] as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return (int) $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildInvoiceSearchFilters(ElectronicInvoice $invoice): array
    {
        $filters = [];

        if (!empty($invoice->cufe)) {
            $filters[] = ['cufe' => $invoice->cufe];
        }

        if (!empty($invoice->reference_code)) {
            $filters[] = ['reference_code' => $invoice->reference_code];
        }

        if (!empty($invoice->document)) {
            $filters[] = ['number' => $invoice->document];
        }

        return $filters;
    }

    /**
     * @param Collection<int, mixed> $items
     * @return array{subtotal: float, tax: float, total: float}
     */
    private function calculateTotals(Collection $items): array
    {
        $subtotal = 0.0;
        $tax = 0.0;
        $total = 0.0;

        foreach ($items as $item) {
            $normalizedItem = $this->normalizeItemData($item);
            $lineSubtotal = round($normalizedItem['quantity'] * $normalizedItem['price'], 2);

            $subtotal += $lineSubtotal;
            $tax += $normalizedItem['tax_amount'];
            $total += $normalizedItem['total'];
        }

        return [
            'subtotal' => round($subtotal, 2),
            'tax' => round($tax, 2),
            'total' => round($total, 2),
        ];
    }

    /**
     * @param mixed $item
     * @return array{code_reference: string, name: string, note: ?string, quantity: float, price: float, tax_rate: float, tax_amount: float, total: float, discount_rate: float, is_excluded: bool, unit_measure_id: int, standard_code_id: int, tribute_id: int}
     */
    private function normalizeItemData(mixed $item): array
    {
        $item = is_array($item) ? $item : [];
        $name = trim((string) ($item['name'] ?? ''));

        if ($name === '') {
            throw new \InvalidArgumentException('Cada item de la nota credito debe tener nombre.');
        }

        $quantity = (float) ($item['quantity'] ?? 0);
        $price = (float) ($item['price'] ?? 0);
        $taxRate = (float) ($item['tax_rate'] ?? 0);
        $discountRate = (float) ($item['discount_rate'] ?? 0);
        $lineSubtotal = round($quantity * $price, 2);
        $taxAmount = round($lineSubtotal * ($taxRate / 100), 2);

        return [
            'code_reference' => trim((string) ($item['code_reference'] ?? '')),
            'name' => $name,
            'note' => $this->normalizeObservation($item['note'] ?? null),
            'quantity' => $quantity,
            'price' => $price,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => round($lineSubtotal + $taxAmount, 2),
            'discount_rate' => $discountRate,
            'is_excluded' => (bool) ($item['is_excluded'] ?? false),
            'unit_measure_id' => (int) ($item['unit_measure_id'] ?? self::DEFAULT_UNIT_MEASURE_ID),
            'standard_code_id' => (int) ($item['standard_code_id'] ?? self::DEFAULT_STANDARD_CODE_ID),
            'tribute_id' => (int) ($item['tribute_id'] ?? self::DEFAULT_TRIBUTE_ID),
        ];
    }

    /**
     * @param array<string, mixed> $creditNoteData
     */
    private function mapStatusFromResponse(array $creditNoteData, ?array $summaryData = null): string
    {
        if ($this->extractValidatedAtFromCreditNoteData($creditNoteData) !== null) {
            return 'accepted';
        }

        $statuses = array_filter([
            $this->normalizeFactusCreditNoteStatus($creditNoteData['status'] ?? null),
            $this->normalizeFactusCreditNoteStatus($summaryData['status'] ?? null),
        ]);

        if (in_array('rejected', $statuses, true)) {
            return 'rejected';
        }

        if (in_array('cancelled', $statuses, true)) {
            return 'cancelled';
        }

        if (in_array('accepted', $statuses, true)) {
            return 'accepted';
        }

        if (in_array('pending', $statuses, true)) {
            return 'pending';
        }

        if (!empty($creditNoteData['cude'])) {
            return 'pending';
        }

        return 'pending';
    }

    private function normalizeFactusCreditNoteStatus(mixed $status): ?string
    {
        if (is_int($status) || (is_string($status) && is_numeric($status))) {
            return match ((int) $status) {
                1 => 'accepted',
                0 => 'pending',
                default => null,
            };
        }

        if (!is_string($status)) {
            return null;
        }

        $normalized = strtolower(trim($status));

        return in_array($normalized, ['accepted', 'rejected', 'pending', 'cancelled'], true)
            ? $normalized
            : null;
    }

    private function generateReferenceCode(): string
    {
        return 'NC-' . date('Ymd') . '-' . strtoupper(uniqid());
    }

    private function generateDocumentNumber(FactusNumberingRange $range): string
    {
        return ($range->prefix ?? 'NC') . $range->current;
    }

    private function normalizeObservation(?string $observation): ?string
    {
        $normalized = trim((string) ($observation ?? ''));

        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, self::FACTUS_OBSERVATION_MAX_LENGTH);
    }

    private function resolveSyncPayload(ElectronicCreditNote $creditNote): array
    {
        return is_array($creditNote->payload_sent) && $creditNote->payload_sent !== []
            ? $creditNote->payload_sent
            : $this->buildPayload($creditNote);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCustomerPayload(ElectronicCreditNote $creditNote): array
    {
        $customer = $creditNote->customer;
        $taxProfile = $customer?->taxProfile;
        $identificationDocument = $taxProfile?->identificationDocument;
        $municipality = $taxProfile?->municipality;

        if (!$customer || !$taxProfile || !$identificationDocument || !$municipality) {
            throw new \RuntimeException(
                'El cliente de la nota credito no tiene perfil fiscal completo para Factus.'
            );
        }

        $payload = [
            'identification_document_id' => $identificationDocument->id,
            'identification' => $taxProfile->identification,
            'municipality_id' => $municipality->factus_id,
            'legal_organization_id' => $taxProfile->legal_organization_id,
            'tribute_id' => $taxProfile->tribute_id,
        ];

        if ($identificationDocument->code === 'NIT' && $taxProfile->dv !== null && $taxProfile->dv !== '') {
            $payload['dv'] = (string) $taxProfile->dv;
        }

        if ($identificationDocument->code === 'NIT') {
            $payload['names'] = $taxProfile->company ?: $customer->name;
            $payload['company'] = $taxProfile->company ?: $customer->name;
        } else {
            $payload['names'] = $taxProfile->names ?: $customer->name;
        }

        if (!empty($customer->address)) {
            $payload['address'] = $customer->address;
        }

        if (!empty($customer->email)) {
            $payload['email'] = $customer->email;
        }

        if (!empty($customer->phone)) {
            $payload['phone'] = $customer->phone;
        }

        return $payload;
    }

    private function parseFactusDateTime(string $value): ?Carbon
    {
        foreach ([
            'd-m-Y h:i:s A',
            'd-m-Y H:i:s',
            'Y-m-d H:i:s',
        ] as $format) {
            try {
                return Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                // Intentar siguiente formato.
            }
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractValidatedAtFromCreditNoteData(array $creditNoteData): ?Carbon
    {
        $value = $creditNoteData['validated'] ?? $creditNoteData['validated_at'] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->parseFactusDateTime($value);
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $logContext
     */
    private function applySuccessfulFactusCreditNoteResponse(
        ElectronicCreditNote $creditNote,
        array $payload,
        array $response,
        ?array $summaryData = null,
        array $logContext = []
    ): void {
        $creditNoteData = $response['data']['credit_note'] ?? null;

        if (!is_array($creditNoteData)) {
            throw new \RuntimeException('Respuesta invalida de Factus al crear la nota credito.');
        }

        $status = $this->mapStatusFromResponse($creditNoteData, $summaryData);
        $validatedAt = $this->extractValidatedAtFromCreditNoteData($creditNoteData);

        $updateData = [
            'status' => $status,
            'payload_sent' => $payload,
            'response_dian' => $response,
            'validated_at' => $validatedAt,
        ];

        if (!empty($creditNoteData['id'])) {
            $updateData['factus_credit_note_id'] = (int) $creditNoteData['id'];
        }

        if (!empty($creditNoteData['number'])) {
            $updateData['document'] = $creditNoteData['number'];
        }

        if (!empty($creditNoteData['cude'])) {
            $updateData['cude'] = $creditNoteData['cude'];
        }

        if (!empty($creditNoteData['qr'])) {
            $updateData['qr'] = $creditNoteData['qr'];
        }

        foreach ([
            'gross_value' => 'gross_value',
            'tax_amount' => 'tax_amount',
            'discount_amount' => 'discount_amount',
            'surcharge_amount' => 'surcharge_amount',
            'total' => 'total',
        ] as $responseKey => $localKey) {
            if (isset($creditNoteData[$responseKey]) && $creditNoteData[$responseKey] !== null && $creditNoteData[$responseKey] !== '') {
                $updateData[$localKey] = (float) $creditNoteData[$responseKey];
            }
        }

        $creditNote->update($updateData);

        Log::info('Nota credito sincronizada exitosamente con Factus', array_merge([
            'credit_note_id' => $creditNote->id,
            'status' => $updateData['status'],
            'document' => $updateData['document'] ?? $creditNote->document,
        ], $logContext));
    }

    private function persistFailedFactusCreditNoteAttempt(
        ElectronicCreditNote $creditNote,
        array $payload,
        FactusApiException $exception,
        ?array $cleanupContext = null
    ): void {
        $responseDian = $exception->getResponseBody();

        if (!is_array($responseDian)) {
            $responseDian = [
                'message' => $exception->getMessage(),
            ];
        }

        if ($cleanupContext !== null && $cleanupContext !== []) {
            $responseDian['_cleanup'] = $cleanupContext;
        }

        $creditNote->update([
            'status' => $this->determineFailedCreditNoteStatus($exception, $cleanupContext),
            'payload_sent' => $payload,
            'response_dian' => $responseDian,
        ]);
    }

    private function determineFailedCreditNoteStatus(
        FactusApiException $exception,
        ?array $cleanupContext = null
    ): string {
        if ($exception->getStatusCode() === 422) {
            return 'rejected';
        }

        if ($this->isPendingCreditNoteConflict($exception)
            && (int) data_get($cleanupContext, 'current_reference_cleanup.status_code') === 404
        ) {
            return 'cancelled';
        }

        return 'pending';
    }

    private function buildFactusErrorMessage(FactusApiException $exception, ?array $cleanupContext = null): string
    {
        $message = "Error en Factus API ({$exception->getStatusCode()}): {$exception->getMessage()}";
        $errorMessages = $this->extractFactusErrorMessages($exception->getResponseBody());

        if ($errorMessages !== []) {
            $message .= ' | Detalle: ' . implode(' | ', $errorMessages);
        }

        if (!empty($cleanupContext['current_reference_cleanup']['success'])) {
            $message .= ' | Factus dejo un pendiente oculto y fue limpiado automaticamente.';
        } elseif ($exception->getStatusCode() === 409 && $this->isPendingCreditNoteConflict($exception)) {
            $message .= ' | Revisa las notas credito pendientes con el comando php artisan factus:credit-notes-pending.';
        }

        return $message;
    }

    /**
     * @param mixed $responseBody
     * @return array<int, string>
     */
    private function extractFactusErrorMessages(mixed $responseBody): array
    {
        if (!is_array($responseBody)) {
            return [];
        }

        $errors = data_get($responseBody, 'data.errors');

        if (!is_array($errors)) {
            return [];
        }

        $messages = [];

        foreach ($errors as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $messages[] = trim($value);

                continue;
            }

            if (is_array($value)) {
                foreach ($value as $nestedValue) {
                    if (is_string($nestedValue) && trim($nestedValue) !== '') {
                        $messages[] = trim($nestedValue);
                    }
                }

                continue;
            }

            if (is_string($key) && trim($key) !== '') {
                $messages[] = trim($key);
            }
        }

        return array_values(array_unique($messages));
    }

    private function isPendingCreditNoteConflict(FactusApiException $exception): bool
    {
        if ($exception->getStatusCode() !== 409) {
            return false;
        }

        $normalizedMessage = mb_strtolower($exception->getMessage());
        $normalizedMessage = str_replace(['crédito', 'crÃ©dito', 'crã©dito', 'crãƒâ©dito'], 'credito', $normalizedMessage);

        return str_contains($normalizedMessage, 'nota credito pendiente por enviar a la dian')
            || preg_match('/nota\s+cr\S*dito\s+pendiente\s+por\s+enviar\s+a\s+la\s+dian/', $normalizedMessage) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanupCurrentFactusCreditNoteReference(ElectronicCreditNote $creditNote): array
    {
        if (empty($creditNote->reference_code)) {
            return [];
        }

        try {
            $response = $this->apiService->deleteCreditNoteByReference($creditNote->reference_code);

            return [
                'current_reference_cleanup' => [
                    'success' => true,
                    'reference_code' => $creditNote->reference_code,
                    'response' => $response,
                ],
            ];
        } catch (FactusApiException $cleanupException) {
            return [
                'current_reference_cleanup' => [
                    'success' => false,
                    'reference_code' => $creditNote->reference_code,
                    'status_code' => $cleanupException->getStatusCode(),
                    'response' => $cleanupException->getResponseBody(),
                    'message' => $cleanupException->getMessage(),
                ],
            ];
        } catch (\Throwable $cleanupException) {
            return [
                'current_reference_cleanup' => [
                    'success' => false,
                    'reference_code' => $creditNote->reference_code,
                    'message' => $cleanupException->getMessage(),
                ],
            ];
        }
    }

    private function successResponseHasAlreadyProcessedError(array $response): bool
    {
        $errors = $response['data']['credit_note']['errors'] ?? [];

        if (!is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            if (!is_string($error)) {
                continue;
            }

            $normalized = mb_strtolower($error);

            if (str_contains($normalized, 'regla: 90') || str_contains($normalized, 'documento procesado anteriormente')) {
                return true;
            }
        }

        return false;
    }

    private function markCreditNoteAsRejectedFromSuccessRegla90(
        ElectronicCreditNote $creditNote,
        array $payload,
        array $response
    ): void {
        $creditNote->update([
            'status' => 'rejected',
            'payload_sent' => $payload,
            'response_dian' => $response,
        ]);

        Log::warning('Nota credito marcada como rechazada: Regla 90 detectada en respuesta HTTP 200 de Factus', [
            'credit_note_id' => $creditNote->id,
            'reference_code' => $creditNote->reference_code,
        ]);
    }

    private function isAlreadyProcessedCreditNoteError(FactusApiException $exception): bool
    {
        if ($exception->getStatusCode() !== 422) {
            return false;
        }

        $message = $this->buildFactusErrorMessage($exception);
        $message = mb_strtolower($message);

        return str_contains($message, 'documento procesado anteriormente')
            || str_contains($message, 'regla: 90');
    }

    private function syncExistingProcessedCreditNoteByReference(
        ElectronicCreditNote $creditNote,
        array $payload,
        ?array $cleanupContext = null
    ): bool {
        return $this->syncRemoteCreditNoteSnapshot($creditNote, $payload, [
            'flow' => 'sync_existing_processed_credit_note',
            'cleanup_context' => $cleanupContext,
        ]);
    }

    private function syncRemoteCreditNoteSnapshot(
        ElectronicCreditNote $creditNote,
        array $payload,
        array $logContext = []
    ): bool {
        try {
            $summary = $this->findRemoteCreditNoteSummary($creditNote);

            if (!is_array($summary)) {
                return $this->syncRemoteCreditNoteByDocument($creditNote, $payload, $logContext);
            }

            $number = (string) (data_get($summary, 'number') ?? '');
            if ($number === '') {
                return false;
            }

            $creditNoteData = $this->apiService->getCreditNoteByNumber($number);
            if (!is_array($creditNoteData)) {
                $creditNoteData = $summary;
            }

            $mergedCreditNoteData = $creditNoteData;

            foreach (['id', 'number', 'reference_code', 'status', 'cude', 'qr', 'validated', 'validated_at', 'errors'] as $key) {
                if (
                    (!array_key_exists($key, $mergedCreditNoteData) || $mergedCreditNoteData[$key] === null || $mergedCreditNoteData[$key] === '')
                    && array_key_exists($key, $summary)
                ) {
                    $mergedCreditNoteData[$key] = $summary[$key];
                }
            }

            $this->applySuccessfulFactusCreditNoteResponse($creditNote, $payload, [
                'data' => [
                    'credit_note' => $mergedCreditNoteData,
                ],
            ], $summary, $logContext);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('No se pudo sincronizar la nota credito ya procesada desde Factus', [
                'credit_note_id' => $creditNote->id,
                'reference_code' => $creditNote->reference_code,
                'error' => $exception->getMessage(),
            ]);

            return $this->syncRemoteCreditNoteByDocument($creditNote, $payload, $logContext);
        }
    }

    private function findRemoteCreditNoteSummary(ElectronicCreditNote $creditNote): ?array
    {
        $referenceSummary = $this->findRemoteCreditNoteSummaryByFilter(
            ['reference_code' => $creditNote->reference_code],
            static fn (array $item, ElectronicCreditNote $creditNote): bool => data_get($item, 'reference_code') === $creditNote->reference_code,
            $creditNote
        );

        $numberSummary = $this->findRemoteCreditNoteSummaryByFilter(
            ['number' => $creditNote->document],
            static fn (array $item, ElectronicCreditNote $creditNote): bool => data_get($item, 'number') === $creditNote->document,
            $creditNote
        );

        return $this->pickBestRemoteCreditNoteSummary($referenceSummary, $numberSummary);
    }

    /**
     * @param array<string, mixed> $filters
     * @param callable(array<string, mixed>, ElectronicCreditNote): bool $matcher
     * @return array<string, mixed>|null
     */
    private function findRemoteCreditNoteSummaryByFilter(
        array $filters,
        callable $matcher,
        ElectronicCreditNote $creditNote
    ): ?array {
        $filters = array_filter($filters, static fn ($value): bool => filled($value));

        if ($filters === []) {
            return null;
        }

        try {
            $response = $this->apiService->getCreditNotes($filters, 1, 10);
            $items = $response['data']['data'] ?? $response['data'] ?? null;

            if (!is_array($items)) {
                return null;
            }

            foreach ($items as $item) {
                if (is_array($item) && $matcher($item, $creditNote)) {
                    return $item;
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('No se pudo consultar el resumen remoto de la nota credito en Factus', [
                'credit_note_id' => $creditNote->id,
                'filters' => $filters,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $referenceSummary
     * @param array<string, mixed>|null $numberSummary
     * @return array<string, mixed>|null
     */
    private function pickBestRemoteCreditNoteSummary(?array $referenceSummary, ?array $numberSummary): ?array
    {
        $candidates = array_values(array_filter([$referenceSummary, $numberSummary], 'is_array'));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $left, array $right): int {
            return $this->scoreRemoteCreditNoteSummary($right) <=> $this->scoreRemoteCreditNoteSummary($left);
        });

        return $candidates[0];
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function scoreRemoteCreditNoteSummary(array $summary): int
    {
        if ($this->extractValidatedAtFromCreditNoteData($summary) !== null) {
            return 40;
        }

        return match ($this->normalizeFactusCreditNoteStatus($summary['status'] ?? null)) {
            'accepted' => 30,
            'rejected' => 20,
            'pending' => 10,
            'cancelled' => 5,
            default => 0,
        };
    }

    private function syncRemoteCreditNoteByDocument(
        ElectronicCreditNote $creditNote,
        array $payload,
        array $logContext = []
    ): bool {
        $number = trim((string) ($creditNote->document ?? ''));

        if ($number === '') {
            return false;
        }

        try {
            $creditNoteData = $this->apiService->getCreditNoteByNumber($number);

            if (!is_array($creditNoteData)) {
                return false;
            }

            $this->applySuccessfulFactusCreditNoteResponse($creditNote, $payload, [
                'data' => [
                    'credit_note' => $creditNoteData,
                ],
            ], null, $logContext);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('No se pudo sincronizar la nota credito desde Factus por numero', [
                'credit_note_id' => $creditNote->id,
                'document' => $number,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function validateCustomerTaxProfileForFactus(Customer $customer): void
    {
        $taxProfile = $customer->taxProfile;
        $identificationDocument = $taxProfile?->identificationDocument;

        if (!$taxProfile || !$identificationDocument) {
            throw new \Exception('El cliente no tiene perfil fiscal listo para Factus.');
        }

        $missingFields = [];

        foreach ([
            'identification_document_id' => 'tipo de documento',
            'identification' => 'numero de documento',
            'legal_organization_id' => 'organizacion juridica',
            'tribute_id' => 'tributo',
            'municipality_id' => 'municipio',
        ] as $field => $label) {
            if (empty($taxProfile->{$field})) {
                $missingFields[] = $label;
            }
        }

        if ($identificationDocument->code === 'NIT') {
            if (empty($taxProfile->company) && empty($customer->name)) {
                $missingFields[] = 'razon social';
            }

            $expectedDv = $this->calculateDV((string) $taxProfile->identification);
            $currentDv = $taxProfile->dv !== null ? (int) $taxProfile->dv : null;

            if ($currentDv === null) {
                $missingFields[] = 'DV';
            } elseif ($currentDv !== $expectedDv) {
                Log::warning('DV del cliente no coincide con el calculo local, se enviara el valor almacenado para validacion final de Factus en nota credito.', [
                    'customer_id' => $customer->id,
                    'identification' => $taxProfile->identification,
                    'stored_dv' => $currentDv,
                    'calculated_dv' => $expectedDv,
                ]);
            }
        } elseif (empty($taxProfile->names) && empty($customer->name)) {
            $missingFields[] = 'nombre del cliente';
        }

        if ($missingFields !== []) {
            throw new \Exception(
                'El cliente no tiene perfil fiscal completo para emitir la nota credito. Faltan: ' . implode(', ', $missingFields) . '.'
            );
        }
    }

    private function calculateDV(string $nit): int
    {
        $nit = preg_replace('/[^0-9]/', '', $nit);

        if (empty($nit)) {
            return 0;
        }

        $weights = [41, 37, 33, 29, 25, 23, 19, 17, 13, 11, 7, 3, 1];
        $sum = 0;
        $nitReversed = strrev($nit);
        $length = strlen($nitReversed);

        for ($index = 0; $index < $length && $index < 13; $index++) {
            $sum += (int) $nitReversed[$index] * $weights[$index];
        }

        $mod = $sum % 11;

        return $mod < 2 ? $mod : 11 - $mod;
    }
}

