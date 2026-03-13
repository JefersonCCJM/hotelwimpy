<?php

namespace App\Services;

use App\Exceptions\FactusApiException;
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
        $invoice->loadMissing(['customer', 'items']);

        $this->ensureInvoiceCanGenerateCreditNote($invoice);

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

    private function sendToFactus(ElectronicCreditNote $creditNote): void
    {
        $creditNote->loadMissing(['items', 'electronicInvoice']);

        $payload = $this->buildPayload($creditNote);

        Log::info('Payload enviado a Factus para nota credito', [
            'credit_note_id' => $creditNote->id,
            'electronic_invoice_id' => $creditNote->electronic_invoice_id,
            'payload' => $payload,
        ]);

        try {
            $response = $this->apiService->post('/v1/credit-notes/validate', $payload);
            $creditNoteData = $response['data']['credit_note'] ?? null;

            if (!is_array($creditNoteData)) {
                throw new \RuntimeException('Respuesta invalida de Factus al crear la nota credito.');
            }

            $updateData = [
                'status' => $this->mapStatusFromResponse($creditNoteData),
                'payload_sent' => $payload,
                'response_dian' => $response,
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

            if (!empty($creditNoteData['validated'])) {
                $updateData['validated_at'] = $this->parseFactusDateTime($creditNoteData['validated']);
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

            Log::info('Nota credito enviada exitosamente a Factus', [
                'credit_note_id' => $creditNote->id,
                'status' => $updateData['status'],
                'document' => $updateData['document'] ?? $creditNote->document,
            ]);
        } catch (FactusApiException $exception) {
            $this->persistFailedFactusCreditNoteAttempt($creditNote, $payload, $exception);

            Log::error('Error sending credit note to Factus API', [
                'credit_note_id' => $creditNote->id,
                'status_code' => $exception->getStatusCode(),
                'error_message' => $exception->getMessage(),
                'error_data' => $exception->getResponseBody(),
                'payload' => $payload,
            ]);

            throw new \Exception(
                'Error al enviar la nota credito a Factus: ' . $this->buildFactusErrorMessage($exception)
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
                    'tribute_id' => (int) ($item->tribute_id ?: self::DEFAULT_TRIBUTE_ID),
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

    private function resolveNumberingRange(int $numberingRangeId): FactusNumberingRange
    {
        $numberingRange = FactusNumberingRange::findOrFail($numberingRangeId);

        if (!$numberingRange->isValid() || $numberingRange->document !== 'Nota Crédito') {
            throw new \InvalidArgumentException('El rango de numeracion seleccionado no corresponde a notas credito activas.');
        }

        return $numberingRange;
    }

    private function resolveReferencedFactusBillId(ElectronicInvoice $invoice): int
    {
        if (!empty($invoice->factus_bill_id)) {
            return (int) $invoice->factus_bill_id;
        }

        $responseBillId = $this->extractBillIdFromStoredResponse($invoice->response_dian ?? []);
        if ($responseBillId !== null) {
            $invoice->update(['factus_bill_id' => $responseBillId]);

            return $responseBillId;
        }

        foreach ($this->buildInvoiceSearchFilters($invoice) as $filters) {
            try {
                $response = $this->apiService->getBills($filters, 1, 1);
                $bill = $this->extractFirstBill($response);

                if (!empty($bill['id'])) {
                    $factusBillId = (int) $bill['id'];
                    $invoice->update(['factus_bill_id' => $factusBillId]);

                    return $factusBillId;
                }
            } catch (\Exception $exception) {
                Log::warning('No se pudo resolver bill_id de Factus para factura referenciada', [
                    'electronic_invoice_id' => $invoice->id,
                    'filters' => $filters,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        throw new \RuntimeException(
            'No fue posible resolver el bill_id de Factus para la factura seleccionada. Actualiza el estado de la factura o recreala antes de emitir la nota credito.'
        );
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
    private function mapStatusFromResponse(array $creditNoteData): string
    {
        $status = $creditNoteData['status'] ?? null;

        if (is_string($status)) {
            $normalized = strtolower($status);

            if (in_array($normalized, ['accepted', 'rejected', 'pending', 'cancelled'], true)) {
                return $normalized;
            }
        }

        if ((string) $status === '1' || !empty($creditNoteData['cude'])) {
            return 'accepted';
        }

        return 'pending';
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

    private function persistFailedFactusCreditNoteAttempt(
        ElectronicCreditNote $creditNote,
        array $payload,
        FactusApiException $exception
    ): void {
        $responseDian = $exception->getResponseBody();

        if (!is_array($responseDian)) {
            $responseDian = [
                'message' => $exception->getMessage(),
            ];
        }

        $creditNote->update([
            'status' => $exception->getStatusCode() === 422 ? 'rejected' : 'pending',
            'payload_sent' => $payload,
            'response_dian' => $responseDian,
        ]);
    }

    private function buildFactusErrorMessage(FactusApiException $exception): string
    {
        $message = "Error en Factus API ({$exception->getStatusCode()}): {$exception->getMessage()}";
        $errorMessages = $this->extractFactusErrorMessages($exception->getResponseBody());

        if ($errorMessages !== []) {
            $message .= ' | Detalle: ' . implode(' | ', $errorMessages);
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
}
