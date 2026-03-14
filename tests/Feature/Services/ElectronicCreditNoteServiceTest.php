<?php

namespace Tests\Feature\Services;

use App\Exceptions\FactusApiException;
use App\Models\Customer;
use App\Models\CustomerTaxProfile;
use App\Models\DianIdentificationDocument;
use App\Models\DianLegalOrganization;
use App\Models\DianMeasurementUnit;
use App\Models\DianMunicipality;
use App\Models\DianPaymentMethod;
use App\Models\ElectronicCreditNote;
use App\Models\ElectronicInvoice;
use App\Models\ElectronicInvoiceItem;
use App\Models\FactusNumberingRange;
use App\Services\ElectronicCreditNoteService;
use App\Services\FactusApiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ElectronicCreditNoteServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestingSchema();
        $this->seedCatalogs();
    }

    #[Test]
    public function it_creates_and_validates_a_credit_note_using_the_referenced_factus_bill_id(): void
    {
        [$invoice, $creditNoteRange] = $this->createAcceptedInvoiceFixture(
            customerName: 'Alan Turing',
            invoiceReference: 'INV-001',
            invoiceDocument: 'SETP990000302',
            invoiceCufe: 'CUFE-123456',
            itemCodeReference: 'HSP-001',
            itemName: 'Hospedaje una noche'
        );
        $invoice->update(['factus_bill_id' => 999]);
        $invoice->refresh();

        $capturedPayload = null;

        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('getBills')
            ->once()
            ->with(Mockery::type('array'), 1, 1)
            ->andReturn([
                'data' => [
                    'data' => [
                        [
                            'id' => 514,
                            'number' => 'SETP990000302',
                        ],
                    ],
                ],
            ]);
        $factusApi->shouldReceive('post')
            ->once()
            ->with('/v1/credit-notes/validate', Mockery::on(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return true;
            }))
            ->andReturn([
                'data' => [
                    'credit_note' => [
                        'id' => 132,
                        'number' => 'NC76',
                        'status' => 1,
                        'qr' => 'https://factus.test/qr/nc76',
                        'cude' => 'CUDE-123456',
                        'validated' => '20-09-2024 09:13:43 AM',
                        'gross_value' => '100000.00',
                        'tax_amount' => '19000.00',
                        'discount_amount' => '0.00',
                        'surcharge_amount' => '0.00',
                        'total' => '119000.00',
                        'bill_id' => 514,
                    ],
                ],
            ]);

        $service = new ElectronicCreditNoteService($factusApi);

        $creditNote = $service->createFromInvoice($invoice, [
            'numbering_range_id' => $creditNoteRange->id,
            'correction_concept_code' => 2,
            'payment_method_code' => '10',
            'notes' => 'Anulacion total de la factura',
            'send_email' => true,
            'items' => [
                [
                    'code_reference' => 'HSP-001',
                    'name' => 'Hospedaje una noche',
                    'quantity' => 1,
                    'price' => 100000,
                    'tax_rate' => 19,
                    'unit_measure_id' => 70,
                    'standard_code_id' => 1,
                    'tribute_id' => 18,
                    'is_excluded' => false,
                ],
            ],
        ]);

        $storedCreditNote = $creditNote->fresh();

        $this->assertSame('accepted', $storedCreditNote->status);
        $this->assertSame(132, $storedCreditNote->factus_credit_note_id);
        $this->assertSame(514, $storedCreditNote->referenced_factus_bill_id);
        $this->assertSame('NC76', $storedCreditNote->document);
        $this->assertSame('CUDE-123456', $storedCreditNote->cude);
        $this->assertNotNull($capturedPayload);
        $this->assertSame(1275, $capturedPayload['numbering_range_id']);
        $this->assertSame(2, $capturedPayload['correction_concept_code']);
        $this->assertSame(20, $capturedPayload['customization_id']);
        $this->assertSame(514, $capturedPayload['bill_id']);
        $this->assertSame('10', $capturedPayload['payment_method_code']);
        $this->assertSame($invoice->customer->taxProfile->identification_document_id, $capturedPayload['customer']['identification_document_id']);
        $this->assertSame($invoice->customer->taxProfile->identification, $capturedPayload['customer']['identification']);
        $this->assertSame($invoice->customer->taxProfile->legal_organization_id, $capturedPayload['customer']['legal_organization_id']);
        $this->assertSame($invoice->customer->taxProfile->tribute_id, $capturedPayload['customer']['tribute_id']);
        $this->assertSame('Anulacion total de la factura', $capturedPayload['observation']);
        $this->assertSame(1, $capturedPayload['items'][0]['tribute_id']);
    }

    #[Test]
    public function it_persists_factus_validation_errors_and_exposes_the_detail_to_the_user(): void
    {
        [$invoice, $creditNoteRange] = $this->createAcceptedInvoiceFixture(
            customerName: 'Grace Hopper',
            invoiceReference: 'INV-002',
            invoiceDocument: 'SETP990000303',
            invoiceCufe: 'CUFE-654321',
            itemCodeReference: 'HSP-002',
            itemName: 'Hospedaje dos noches'
        );

        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('getBills')
            ->once()
            ->with(Mockery::type('array'), 1, 1)
            ->andReturn([
                'data' => [
                    'data' => [
                        [
                            'id' => 514,
                            'number' => 'SETP990000303',
                        ],
                    ],
                ],
            ]);
        $factusApi->shouldReceive('post')
            ->once()
            ->with('/v1/credit-notes/validate', Mockery::type('array'))
            ->andThrow(new FactusApiException(
                'Error de validacion',
                422,
                [
                    'message' => 'Validation failed.',
                    'data' => [
                        'errors' => [
                            'bill_id' => [
                                'La factura referenciada es obligatoria.',
                            ],
                            'customer' => [
                                'El cliente no es valido.',
                            ],
                        ],
                    ],
                ]
            ));

        $service = new ElectronicCreditNoteService($factusApi);

        try {
            $service->createFromInvoice($invoice, [
                'numbering_range_id' => $creditNoteRange->id,
                'correction_concept_code' => 2,
                'payment_method_code' => '10',
                'notes' => 'Anulacion total de la factura',
                'send_email' => true,
                'items' => [
                    [
                        'code_reference' => 'HSP-002',
                        'name' => 'Hospedaje dos noches',
                        'quantity' => 1,
                        'price' => 100000,
                        'tax_rate' => 19,
                        'unit_measure_id' => 70,
                        'standard_code_id' => 1,
                        'tribute_id' => 18,
                        'is_excluded' => false,
                    ],
                ],
            ]);

            $this->fail('Expected credit note creation to fail.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('Error en Factus API (422): Error de validacion', $exception->getMessage());
            $this->assertStringContainsString('La factura referenciada es obligatoria.', $exception->getMessage());
            $this->assertStringContainsString('El cliente no es valido.', $exception->getMessage());
        }

        $storedCreditNote = ElectronicCreditNote::query()->latest('id')->firstOrFail();

        $this->assertSame('rejected', $storedCreditNote->status);
        $this->assertSame(
            ['La factura referenciada es obligatoria.'],
            data_get($storedCreditNote->response_dian, 'data.errors.bill_id')
        );
        $this->assertSame(['El cliente no es valido.'], data_get($storedCreditNote->response_dian, 'data.errors.customer'));
        $this->assertSame(1, data_get($storedCreditNote->payload_sent, 'items.0.tribute_id'));
    }

    #[Test]
    public function it_prevents_creating_a_new_credit_note_when_the_invoice_has_open_attempts(): void
    {
        [$invoice, $creditNoteRange] = $this->createAcceptedInvoiceFixture(
            customerName: 'Katherine Johnson',
            invoiceReference: 'INV-003',
            invoiceDocument: 'SETP990000304',
            invoiceCufe: 'CUFE-777777',
            itemCodeReference: 'HSP-003',
            itemName: 'Hospedaje tres noches'
        );

        ElectronicCreditNote::create([
            'electronic_invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'factus_numbering_range_id' => $creditNoteRange->factus_id,
            'referenced_factus_bill_id' => 514,
            'correction_concept_code' => 2,
            'customization_id' => 20,
            'payment_method_code' => '10',
            'send_email' => true,
            'reference_code' => 'NC-BLOCKED-001',
            'document' => 'NC75',
            'status' => 'pending',
            'gross_value' => 100000,
            'tax_amount' => 19000,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'total' => 119000,
        ]);

        $service = new ElectronicCreditNoteService(Mockery::mock(FactusApiService::class));

        try {
            $service->createFromInvoice($invoice, [
                'numbering_range_id' => $creditNoteRange->id,
                'correction_concept_code' => 2,
                'payment_method_code' => '10',
                'notes' => 'Anulacion total de la factura',
                'send_email' => true,
                'items' => [
                    [
                        'code_reference' => 'HSP-003',
                        'name' => 'Hospedaje tres noches',
                        'quantity' => 1,
                        'price' => 100000,
                        'tax_rate' => 19,
                        'unit_measure_id' => 70,
                        'standard_code_id' => 1,
                        'tribute_id' => 18,
                        'is_excluded' => false,
                    ],
                ],
            ]);

            $this->fail('Expected open credit note attempts to block creation.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Esta factura ya tiene intentos de nota credito pendientes o rechazados (NC75).',
                $exception->getMessage()
            );
        }

        $this->assertSame(1, ElectronicCreditNote::query()->count());

        return;

        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('getBills')
            ->once()
            ->with(Mockery::type('array'), 1, 1)
            ->andReturn([
                'data' => [
                    'data' => [
                        [
                            'id' => 514,
                            'number' => 'SETP990000304',
                        ],
                    ],
                ],
            ]);
        $postAttempts = 0;
        $factusApi->shouldReceive('post')
            ->twice()
            ->with('/v1/credit-notes/validate', Mockery::type('array'))
            ->andReturnUsing(function () use (&$postAttempts): array {
                $postAttempts++;

                if ($postAttempts === 1) {
                    throw new FactusApiException(
                        'Error en Factus API (post /v1/credit-notes/validate): Se encontró una nota crédito pendiente por enviar a la DIAN',
                        409,
                        [
                            'message' => 'Se encontró una nota crédito pendiente por enviar a la DIAN',
                        ]
                    );
                }

                return [
                    'data' => [
                        'credit_note' => [
                            'id' => 133,
                            'number' => 'NC76',
                            'status' => 1,
                            'qr' => 'https://factus.test/qr/nc76',
                            'cude' => 'CUDE-654987',
                            'validated' => '20-09-2024 09:13:43 AM',
                            'gross_value' => '100000.00',
                            'tax_amount' => '19000.00',
                            'discount_amount' => '0.00',
                            'surcharge_amount' => '0.00',
                            'total' => '119000.00',
                            'bill_id' => 514,
                        ],
                    ],
                ];
            });
        $factusApi->shouldReceive('deleteCreditNoteByReference')
            ->once()
            ->with('NC-BLOCKED-001')
            ->andReturn([
                'status' => 'OK',
                'message' => 'Documento eliminado con exito',
            ]);

        $service = new ElectronicCreditNoteService($factusApi);

        $creditNote = $service->createFromInvoice($invoice, [
            'numbering_range_id' => $creditNoteRange->id,
            'correction_concept_code' => 2,
            'payment_method_code' => '10',
            'notes' => 'Anulacion total de la factura',
            'send_email' => true,
            'items' => [
                [
                    'code_reference' => 'HSP-003',
                    'name' => 'Hospedaje tres noches',
                    'quantity' => 1,
                    'price' => 100000,
                    'tax_rate' => 19,
                    'unit_measure_id' => 70,
                    'standard_code_id' => 1,
                    'tribute_id' => 18,
                    'is_excluded' => false,
                ],
            ],
        ]);

        $storedCreditNote = $creditNote->fresh();
        $blockingCreditNote = ElectronicCreditNote::query()->where('reference_code', 'NC-BLOCKED-001')->firstOrFail();

        $this->assertSame('accepted', $storedCreditNote->status);
        $this->assertSame(133, $storedCreditNote->factus_credit_note_id);
        $this->assertSame('cancelled', $blockingCreditNote->status);
        $this->assertSame('Documento eliminado con exito', data_get($blockingCreditNote->response_dian, 'auto_cleanup_response.message'));
    }

    #[Test]
    public function it_prevents_creating_a_second_accepted_total_annulation_for_the_same_invoice(): void
    {
        [$invoice, $creditNoteRange] = $this->createAcceptedInvoiceFixture(
            customerName: 'Linus Torvalds',
            invoiceReference: 'INV-012',
            invoiceDocument: 'SETP990000312',
            invoiceCufe: 'CUFE-121212',
            itemCodeReference: 'HSP-012',
            itemName: 'Hospedaje doce noches'
        );

        ElectronicCreditNote::create([
            'electronic_invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'factus_numbering_range_id' => $creditNoteRange->factus_id,
            'referenced_factus_bill_id' => 514,
            'correction_concept_code' => 2,
            'customization_id' => 20,
            'payment_method_code' => '10',
            'send_email' => true,
            'reference_code' => 'NC-ACCEPTED-012',
            'document' => 'NC1',
            'status' => 'accepted',
            'gross_value' => 100000,
            'tax_amount' => 19000,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'total' => 119000,
        ]);

        $service = new ElectronicCreditNoteService(Mockery::mock(FactusApiService::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Esta factura ya tiene una nota credito de anulacion aceptada (NC1). No puedes emitir otra anulacion total para la misma factura.'
        );

        $service->createFromInvoice($invoice->fresh('creditNotes', 'customer.taxProfile.identificationDocument', 'customer.taxProfile.municipality', 'items'), [
            'numbering_range_id' => $creditNoteRange->id,
            'correction_concept_code' => 2,
            'payment_method_code' => '10',
            'notes' => 'Intento duplicado de anulacion',
            'send_email' => true,
            'items' => [
                [
                    'code_reference' => 'HSP-012',
                    'name' => 'Hospedaje doce noches',
                    'quantity' => 1,
                    'price' => 100000,
                    'tax_rate' => 19,
                    'unit_measure_id' => 70,
                    'standard_code_id' => 1,
                    'tribute_id' => 18,
                    'is_excluded' => false,
                ],
            ],
        ]);
    }

    #[Test]
    public function it_does_not_cancel_other_pending_credit_notes_when_a_409_conflict_happens(): void
    {
        [$invoice, $creditNoteRange] = $this->createAcceptedInvoiceFixture(
            customerName: 'Annie Easley',
            invoiceReference: 'INV-006',
            invoiceDocument: 'SETP990000306',
            invoiceCufe: 'CUFE-606060',
            itemCodeReference: 'HSP-006',
            itemName: 'Hospedaje seis noches'
        );

        $otherInvoice = ElectronicInvoice::create([
            'customer_id' => $invoice->customer_id,
            'factus_numbering_range_id' => $invoice->factus_numbering_range_id,
            'factus_bill_id' => 615,
            'document_type_id' => 1,
            'operation_type_id' => 1,
            'payment_method_code' => '10',
            'payment_form_code' => '1',
            'reference_code' => 'INV-006-B',
            'document' => 'SETP990000406',
            'status' => 'accepted',
            'cufe' => 'CUFE-606061',
            'gross_value' => 100000,
            'tax_amount' => 19000,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'total' => 119000,
            'response_dian' => [],
        ]);

        ElectronicInvoiceItem::create([
            'electronic_invoice_id' => $otherInvoice->id,
            'tribute_id' => 18,
            'standard_code_id' => 1,
            'unit_measure_id' => 70,
            'code_reference' => 'HSP-006-B',
            'name' => 'Hospedaje seis noches segunda factura',
            'quantity' => 1,
            'price' => 100000,
            'tax_rate' => 19,
            'tax_amount' => 19000,
            'discount_rate' => 0,
            'is_excluded' => false,
            'total' => 119000,
        ]);

        $blockingCreditNote = ElectronicCreditNote::create([
            'electronic_invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'factus_numbering_range_id' => $creditNoteRange->factus_id,
            'referenced_factus_bill_id' => 514,
            'correction_concept_code' => 2,
            'customization_id' => 20,
            'payment_method_code' => '10',
            'send_email' => true,
            'reference_code' => 'NC-BLOCKED-006',
            'document' => 'NC80',
            'status' => 'pending',
            'gross_value' => 100000,
            'tax_amount' => 19000,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'total' => 119000,
        ]);

        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('getBills')
            ->once()
            ->with(Mockery::type('array'), 1, 1)
            ->andReturn([
                'data' => [
                    'data' => [
                        [
                            'id' => 615,
                            'number' => 'SETP990000406',
                        ],
                    ],
                ],
            ]);
        $factusApi->shouldReceive('post')
            ->once()
            ->with('/v1/credit-notes/validate', Mockery::type('array'))
            ->andThrow(new FactusApiException(
                'Error en Factus API (post /v1/credit-notes/validate): Se encontró una nota crédito pendiente por enviar a la DIAN',
                409,
                [
                    'message' => 'Se encontró una nota crédito pendiente por enviar a la DIAN',
                ]
            ));
        $factusApi->shouldReceive('deleteCreditNoteByReference')
            ->once()
            ->with('NC-NEW-006')
            ->andThrow(new FactusApiException(
                'No encontrada',
                404,
                ['message' => 'Documento no encontrado']
            ));

        $service = new ElectronicCreditNoteService($factusApi);

        try {
            $service->createFromInvoice($otherInvoice, [
                'numbering_range_id' => $creditNoteRange->id,
                'correction_concept_code' => 2,
                'payment_method_code' => '10',
                'reference_code' => 'NC-NEW-006',
                'notes' => 'Nueva nota sin tocar otras pendientes',
                'send_email' => true,
                'items' => [
                    [
                        'code_reference' => 'HSP-006-B',
                        'name' => 'Hospedaje seis noches segunda factura',
                        'quantity' => 1,
                        'price' => 100000,
                        'tax_rate' => 19,
                        'unit_measure_id' => 70,
                        'standard_code_id' => 1,
                        'tribute_id' => 18,
                        'is_excluded' => false,
                    ],
                ],
            ]);

            $this->fail('Expected the create attempt to fail with a manual cleanup message.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('php artisan factus:credit-notes-pending', $exception->getMessage());
        }

        $newCreditNote = ElectronicCreditNote::query()
            ->where('reference_code', 'NC-NEW-006')
            ->firstOrFail();

        $blockingCreditNote->refresh();
        $this->assertSame('pending', $blockingCreditNote->status);
        $this->assertSame('cancelled', $newCreditNote->status);
    }

    #[Test]
    public function it_verifies_a_pending_credit_note_by_cleaning_its_own_blocking_reference(): void
    {
        [$invoice, $creditNoteRange] = $this->createAcceptedInvoiceFixture(
            customerName: 'Mary Jackson',
            invoiceReference: 'INV-005',
            invoiceDocument: 'SETP990000306',
            invoiceCufe: 'CUFE-999999',
            itemCodeReference: 'HSP-005',
            itemName: 'Hospedaje cinco noches'
        );

        $creditNote = ElectronicCreditNote::create([
            'electronic_invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'factus_numbering_range_id' => $creditNoteRange->factus_id,
            'referenced_factus_bill_id' => 514,
            'correction_concept_code' => 2,
            'customization_id' => 20,
            'payment_method_code' => '10',
            'send_email' => true,
            'reference_code' => 'NC-VERIFY-005',
            'document' => 'NC77',
            'status' => 'pending',
            'gross_value' => 100000,
            'tax_amount' => 19000,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'total' => 119000,
        ]);

        $creditNote->items()->create([
            'tribute_id' => 18,
            'standard_code_id' => 1,
            'unit_measure_id' => 70,
            'code_reference' => 'HSP-005',
            'name' => 'Hospedaje cinco noches',
            'quantity' => 1,
            'price' => 100000,
            'tax_rate' => 19,
            'tax_amount' => 19000,
            'discount_rate' => 0,
            'is_excluded' => false,
            'total' => 119000,
        ]);

        $postAttempts = 0;

        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('post')
            ->twice()
            ->with('/v1/credit-notes/validate', Mockery::type('array'))
            ->andReturnUsing(function () use (&$postAttempts): array {
                $postAttempts++;

                if ($postAttempts === 1) {
                    throw new FactusApiException(
                        'Error en Factus API (post /v1/credit-notes/validate): Se encontrÃ³ una nota crÃ©dito pendiente por enviar a la DIAN',
                        409,
                        [
                            'message' => 'Se encontrÃ³ una nota crÃ©dito pendiente por enviar a la DIAN',
                        ]
                    );
                }

                return [
                    'data' => [
                        'credit_note' => [
                            'id' => 205,
                            'number' => 'NC205',
                            'status' => 1,
                            'qr' => 'https://factus.test/qr/nc205',
                            'cude' => 'CUDE-205205',
                            'validated' => '20-09-2024 09:13:43 AM',
                            'gross_value' => '100000.00',
                            'tax_amount' => '19000.00',
                            'discount_amount' => '0.00',
                            'surcharge_amount' => '0.00',
                            'total' => '119000.00',
                            'bill_id' => 514,
                        ],
                    ],
                ];
            });
        $factusApi->shouldReceive('deleteCreditNoteByReference')
            ->once()
            ->with('NC-VERIFY-005')
            ->andReturn([
                'status' => 'OK',
                'message' => 'Documento eliminado con exito',
            ]);

        $service = new ElectronicCreditNoteService($factusApi);

        $verifiedCreditNote = $service->verifyWithFactus($creditNote);
        $storedCreditNote = $verifiedCreditNote->fresh();

        $this->assertSame('accepted', $storedCreditNote->status);
        $this->assertSame(205, $storedCreditNote->factus_credit_note_id);
        $this->assertSame('NC205', $storedCreditNote->document);
        $this->assertSame('CUDE-205205', $storedCreditNote->cude);
    }

    #[Test]
    public function it_syncs_an_already_processed_credit_note_when_factus_reports_rule_90(): void
    {
        [$invoice, $creditNoteRange] = $this->createAcceptedInvoiceFixture(
            customerName: 'Dorothy Vaughan',
            invoiceReference: 'INV-004',
            invoiceDocument: 'SETP990000305',
            invoiceCufe: 'CUFE-888888',
            itemCodeReference: 'HSP-004',
            itemName: 'Hospedaje cuatro noches'
        );

        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('getBills')
            ->once()
            ->with(Mockery::type('array'), 1, 1)
            ->andReturn([
                'data' => [
                    'data' => [
                        [
                            'id' => 514,
                            'number' => 'SETP990000305',
                        ],
                    ],
                ],
            ]);
        $factusApi->shouldReceive('post')
            ->once()
            ->with('/v1/credit-notes/validate', Mockery::type('array'))
            ->andThrow(new FactusApiException(
                'Error en Factus API (post /v1/credit-notes/validate): El documento contiene errores de validación',
                422,
                [
                    'message' => 'El documento contiene errores de validación',
                    'data' => [
                        'errors' => [
                            '90' => 'Regla: 90, Rechazo: Documento procesado anteriormente.',
                        ],
                    ],
                ]
            ));
        $factusApi->shouldReceive('getCreditNotes')
            ->twice()
            ->with(Mockery::type('array'), 1, 10)
            ->andReturnUsing(function (array $filters): array {
                if (($filters['reference_code'] ?? null) === 'NC-MANUAL-004') {
                    return [
                        'data' => [
                            'data' => [
                                [
                                    'reference_code' => 'NC-MANUAL-004',
                                    'number' => 'NC90',
                                ],
                            ],
                        ],
                    ];
                }

                return ['data' => ['data' => []]];
            });
        $factusApi->shouldReceive('getCreditNoteByNumber')
            ->once()
            ->with('NC90')
            ->andReturn([
                'id' => 190,
                'number' => 'NC90',
                'status' => 1,
                'qr' => 'https://factus.test/qr/nc90',
                'cude' => 'CUDE-909090',
                'validated' => '20-09-2024 09:13:43 AM',
                'gross_value' => '100000.00',
                'tax_amount' => '19000.00',
                'discount_amount' => '0.00',
                'surcharge_amount' => '0.00',
                'total' => '119000.00',
                'reference_code' => 'NC-MANUAL-004',
            ]);

        $service = new ElectronicCreditNoteService($factusApi);

        $creditNote = $service->createFromInvoice($invoice, [
            'numbering_range_id' => $creditNoteRange->id,
            'correction_concept_code' => 2,
            'payment_method_code' => '10',
            'reference_code' => 'NC-MANUAL-004',
            'notes' => 'Anulacion total de la factura',
            'send_email' => true,
            'items' => [
                [
                    'code_reference' => 'HSP-004',
                    'name' => 'Hospedaje cuatro noches',
                    'quantity' => 1,
                    'price' => 100000,
                    'tax_rate' => 19,
                    'unit_measure_id' => 70,
                    'standard_code_id' => 1,
                    'tribute_id' => 18,
                    'is_excluded' => false,
                ],
            ],
        ]);

        $storedCreditNote = $creditNote->fresh();

        $this->assertSame('accepted', $storedCreditNote->status);
        $this->assertSame(190, $storedCreditNote->factus_credit_note_id);
        $this->assertSame('NC90', $storedCreditNote->document);
        $this->assertSame('CUDE-909090', $storedCreditNote->cude);
    }

    #[Test]
    public function it_keeps_a_rule_90_credit_note_as_pending_when_factus_still_reports_it_as_pending(): void
    {
        [$invoice, $creditNoteRange] = $this->createAcceptedInvoiceFixture(
            customerName: 'Margaret Hamilton',
            invoiceReference: 'INV-007',
            invoiceDocument: 'SETP990000307',
            invoiceCufe: 'CUFE-707070',
            itemCodeReference: 'HSP-007',
            itemName: 'Hospedaje siete noches'
        );

        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('getBills')
            ->once()
            ->with(Mockery::type('array'), 1, 1)
            ->andReturn([
                'data' => [
                    'data' => [
                        [
                            'id' => 514,
                            'number' => 'SETP990000307',
                        ],
                    ],
                ],
            ]);
        $factusApi->shouldReceive('post')
            ->once()
            ->with('/v1/credit-notes/validate', Mockery::type('array'))
            ->andThrow(new FactusApiException(
                'Error en Factus API (post /v1/credit-notes/validate): El documento contiene errores de validaciÃ³n',
                422,
                [
                    'message' => 'El documento contiene errores de validaciÃ³n',
                    'data' => [
                        'errors' => [
                            '90' => 'Regla: 90, Rechazo: Documento procesado anteriormente.',
                        ],
                    ],
                ]
            ));
        $factusApi->shouldReceive('getCreditNotes')
            ->twice()
            ->with(Mockery::type('array'), 1, 10)
            ->andReturnUsing(function (array $filters): array {
                if (($filters['reference_code'] ?? null) === 'NC-MANUAL-007') {
                    return [
                        'data' => [
                            'data' => [
                                [
                                    'reference_code' => 'NC-MANUAL-007',
                                    'number' => 'NC91',
                                    'status' => 0,
                                ],
                            ],
                        ],
                    ];
                }

                return ['data' => ['data' => []]];
            });
        $factusApi->shouldReceive('getCreditNoteByNumber')
            ->once()
            ->with('NC91')
            ->andReturn([
                'id' => 191,
                'number' => 'NC91',
                'status' => 0,
                'qr' => 'https://factus.test/qr/nc91',
                'cude' => 'CUDE-919191',
                'gross_value' => '100000.00',
                'tax_amount' => '19000.00',
                'discount_amount' => '0.00',
                'surcharge_amount' => '0.00',
                'total' => '119000.00',
                'reference_code' => 'NC-MANUAL-007',
            ]);

        $service = new ElectronicCreditNoteService($factusApi);

        $creditNote = $service->createFromInvoice($invoice, [
            'numbering_range_id' => $creditNoteRange->id,
            'correction_concept_code' => 2,
            'payment_method_code' => '10',
            'reference_code' => 'NC-MANUAL-007',
            'notes' => 'Anulacion total de la factura',
            'send_email' => true,
            'items' => [
                [
                    'code_reference' => 'HSP-007',
                    'name' => 'Hospedaje siete noches',
                    'quantity' => 1,
                    'price' => 100000,
                    'tax_rate' => 19,
                    'unit_measure_id' => 70,
                    'standard_code_id' => 1,
                    'tribute_id' => 18,
                    'is_excluded' => false,
                ],
            ],
        ]);

        $storedCreditNote = $creditNote->fresh();

        $this->assertSame('pending', $storedCreditNote->status);
        $this->assertSame(191, $storedCreditNote->factus_credit_note_id);
        $this->assertSame('NC91', $storedCreditNote->document);
        $this->assertSame('CUDE-919191', $storedCreditNote->cude);
        $this->assertNull($storedCreditNote->validated_at);
    }

    #[Test]
    public function it_syncs_a_pending_credit_note_as_accepted_when_factus_already_reports_it_as_validated(): void
    {
        [$invoice, $creditNoteRange] = $this->createAcceptedInvoiceFixture(
            customerName: 'Joan Clarke',
            invoiceReference: 'INV-008',
            invoiceDocument: 'SETP990000308',
            invoiceCufe: 'CUFE-808080',
            itemCodeReference: 'HSP-008',
            itemName: 'Hospedaje ocho noches'
        );

        $creditNote = ElectronicCreditNote::create([
            'electronic_invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'factus_numbering_range_id' => $creditNoteRange->factus_id,
            'referenced_factus_bill_id' => 514,
            'correction_concept_code' => 2,
            'customization_id' => 20,
            'payment_method_code' => '10',
            'send_email' => true,
            'reference_code' => 'NC-VERIFY-008',
            'document' => 'NC2',
            'status' => 'pending',
            'gross_value' => 100000,
            'tax_amount' => 19000,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'total' => 119000,
        ]);

        $creditNote->items()->create([
            'tribute_id' => 18,
            'standard_code_id' => 1,
            'unit_measure_id' => 70,
            'code_reference' => 'HSP-008',
            'name' => 'Hospedaje ocho noches',
            'quantity' => 1,
            'price' => 100000,
            'tax_rate' => 19,
            'tax_amount' => 19000,
            'discount_rate' => 0,
            'is_excluded' => false,
            'total' => 119000,
        ]);

        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('getCreditNotes')
            ->twice()
            ->with(Mockery::type('array'), 1, 10)
            ->andReturnUsing(function (array $filters): array {
                if (($filters['reference_code'] ?? null) === 'NC-VERIFY-008') {
                    return [
                        'data' => [
                            'data' => [
                                [
                                    'id' => 3508,
                                    'reference_code' => 'NC-VERIFY-008',
                                    'number' => 'NC2',
                                    'status' => 1,
                                    'errors' => [
                                        'Regla: 90, Rechazo: Documento procesado anteriormente.',
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                return ['data' => ['data' => []]];
            });
        $factusApi->shouldReceive('getCreditNoteByNumber')
            ->once()
            ->with('NC2')
            ->andReturn([
                'id' => 3508,
                'number' => 'NC2',
                'status' => 0,
                'cude' => 'CUDE-828282',
                'qr' => 'https://factus.test/qr/nc2',
                'reference_code' => 'NC-VERIFY-008',
                'gross_value' => '50420.17',
                'tax_amount' => '9579.83',
                'total' => '60000.00',
            ]);
        $factusApi->shouldReceive('post')->never();

        $service = new ElectronicCreditNoteService($factusApi);

        $verifiedCreditNote = $service->verifyWithFactus($creditNote);
        $storedCreditNote = $verifiedCreditNote->fresh();

        $this->assertSame('accepted', $storedCreditNote->status);
        $this->assertSame(3508, $storedCreditNote->factus_credit_note_id);
        $this->assertSame('NC2', $storedCreditNote->document);
        $this->assertSame('CUDE-828282', $storedCreditNote->cude);
    }

    #[Test]
    public function it_prefers_the_number_lookup_when_factus_disagrees_between_reference_and_number_filters(): void
    {
        [$invoice, $creditNoteRange] = $this->createAcceptedInvoiceFixture(
            customerName: 'Ada Lovelace',
            invoiceReference: 'INV-009',
            invoiceDocument: 'SETP990000309',
            invoiceCufe: 'CUFE-909091',
            itemCodeReference: 'HSP-009',
            itemName: 'Hospedaje nueve noches'
        );

        $creditNote = ElectronicCreditNote::create([
            'electronic_invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'factus_numbering_range_id' => $creditNoteRange->factus_id,
            'referenced_factus_bill_id' => 514,
            'correction_concept_code' => 2,
            'customization_id' => 20,
            'payment_method_code' => '10',
            'send_email' => true,
            'reference_code' => 'NC-VERIFY-009',
            'document' => 'NC2',
            'status' => 'pending',
            'gross_value' => 100000,
            'tax_amount' => 19000,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'total' => 119000,
        ]);

        $creditNote->items()->create([
            'tribute_id' => 18,
            'standard_code_id' => 1,
            'unit_measure_id' => 70,
            'code_reference' => 'HSP-009',
            'name' => 'Hospedaje nueve noches',
            'quantity' => 1,
            'price' => 100000,
            'tax_rate' => 19,
            'tax_amount' => 19000,
            'discount_rate' => 0,
            'is_excluded' => false,
            'total' => 119000,
        ]);

        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('getCreditNotes')
            ->once()
            ->with(['reference_code' => 'NC-VERIFY-009'], 1, 10)
            ->andReturn([
                'data' => [
                    'data' => [
                        [
                            'id' => 3510,
                            'reference_code' => 'NC-VERIFY-009',
                            'number' => 'NC2',
                            'status' => 0,
                        ],
                    ],
                ],
            ]);
        $factusApi->shouldReceive('getCreditNotes')
            ->once()
            ->with(['number' => 'NC2'], 1, 10)
            ->andReturn([
                'data' => [
                    'data' => [
                        [
                            'id' => 3510,
                            'reference_code' => 'NC-VERIFY-009',
                            'number' => 'NC2',
                            'status' => 1,
                        ],
                    ],
                ],
            ]);
        $factusApi->shouldReceive('getCreditNoteByNumber')
            ->once()
            ->with('NC2')
            ->andReturn([
                'id' => 3510,
                'number' => 'NC2',
                'status' => 0,
                'cude' => 'CUDE-929292',
                'reference_code' => 'NC-VERIFY-009',
                'gross_value' => '50420.17',
                'tax_amount' => '9579.83',
                'total' => '60000.00',
            ]);
        $factusApi->shouldReceive('post')->never();

        $service = new ElectronicCreditNoteService($factusApi);

        $verifiedCreditNote = $service->verifyWithFactus($creditNote);
        $storedCreditNote = $verifiedCreditNote->fresh();

        $this->assertSame('accepted', $storedCreditNote->status);
        $this->assertSame(3510, $storedCreditNote->factus_credit_note_id);
        $this->assertSame('NC2', $storedCreditNote->document);
        $this->assertSame('CUDE-929292', $storedCreditNote->cude);
    }

    #[Test]
    public function it_cleans_a_pending_credit_note_in_factus_and_marks_it_cancelled_locally(): void
    {
        [$invoice, $creditNoteRange] = $this->createAcceptedInvoiceFixture(
            customerName: 'Barbara Liskov',
            invoiceReference: 'INV-010',
            invoiceDocument: 'SETP990000310',
            invoiceCufe: 'CUFE-101010',
            itemCodeReference: 'HSP-010',
            itemName: 'Hospedaje diez noches'
        );

        $creditNote = ElectronicCreditNote::create([
            'electronic_invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'factus_numbering_range_id' => $creditNoteRange->factus_id,
            'referenced_factus_bill_id' => 514,
            'correction_concept_code' => 2,
            'customization_id' => 20,
            'payment_method_code' => '10',
            'send_email' => true,
            'reference_code' => 'NC-CLEAN-010',
            'document' => 'NC10',
            'status' => 'pending',
            'gross_value' => 100000,
            'tax_amount' => 19000,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'total' => 119000,
            'response_dian' => [],
        ]);

        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('deleteCreditNoteByReference')
            ->once()
            ->with('NC-CLEAN-010')
            ->andReturn([
                'status' => 'OK',
                'message' => 'Documento con código de referencia NC-CLEAN-010 eliminado con éxito',
            ]);

        $service = new ElectronicCreditNoteService($factusApi);

        $cleanedCreditNote = $service->cleanupPendingInFactus($creditNote);
        $storedCreditNote = $cleanedCreditNote->fresh();

        $this->assertSame('cancelled', $storedCreditNote->status);
        $this->assertSame('deleted', data_get($storedCreditNote->response_dian, '_manual_cleanup.result'));
        $this->assertSame(
            'Documento con código de referencia NC-CLEAN-010 eliminado con éxito',
            data_get($storedCreditNote->response_dian, '_manual_cleanup.response.message')
        );
    }

    #[Test]
    public function it_marks_the_credit_note_as_cancelled_when_the_pending_reference_no_longer_exists_in_factus(): void
    {
        [$invoice, $creditNoteRange] = $this->createAcceptedInvoiceFixture(
            customerName: 'Donald Knuth',
            invoiceReference: 'INV-011',
            invoiceDocument: 'SETP990000311',
            invoiceCufe: 'CUFE-111111',
            itemCodeReference: 'HSP-011',
            itemName: 'Hospedaje once noches'
        );

        $creditNote = ElectronicCreditNote::create([
            'electronic_invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'factus_numbering_range_id' => $creditNoteRange->factus_id,
            'referenced_factus_bill_id' => 514,
            'correction_concept_code' => 2,
            'customization_id' => 20,
            'payment_method_code' => '10',
            'send_email' => true,
            'reference_code' => 'NC-CLEAN-011',
            'document' => 'NC11',
            'status' => 'rejected',
            'gross_value' => 100000,
            'tax_amount' => 19000,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'total' => 119000,
            'response_dian' => [],
        ]);

        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('deleteCreditNoteByReference')
            ->once()
            ->with('NC-CLEAN-011')
            ->andThrow(new FactusApiException(
                'No encontrada',
                404,
                ['message' => 'Documento no encontrado']
            ));

        $service = new ElectronicCreditNoteService($factusApi);

        $cleanedCreditNote = $service->cleanupPendingInFactus($creditNote);
        $storedCreditNote = $cleanedCreditNote->fresh();

        $this->assertSame('cancelled', $storedCreditNote->status);
        $this->assertSame('not_found', data_get($storedCreditNote->response_dian, '_manual_cleanup.result'));
        $this->assertSame(
            'Documento no encontrado',
            data_get($storedCreditNote->response_dian, '_manual_cleanup.response.message')
        );
    }

    /**
     * @return array{0: ElectronicInvoice, 1: FactusNumberingRange}
     */
    private function createAcceptedInvoiceFixture(
        string $customerName,
        string $invoiceReference,
        string $invoiceDocument,
        string $invoiceCufe,
        string $itemCodeReference,
        string $itemName
    ): array {
        $municipality = DianMunicipality::create([
            'factus_id' => 980,
            'code' => '68001',
            'name' => 'San Gil',
            'department' => 'Santander',
        ]);

        $identificationDocument = DianIdentificationDocument::create([
            'code' => 'CC',
            'name' => 'Cedula de ciudadania',
            'requires_dv' => false,
        ]);

        $legalOrganization = DianLegalOrganization::create([
            'code' => '2',
            'name' => 'Persona Natural',
        ]);

        $customer = Customer::create([
            'name' => $customerName,
            'email' => strtolower(str_replace(' ', '.', $customerName)) . '@example.com',
            'phone' => '3001234567',
            'address' => 'Calle 123',
            'requires_electronic_invoice' => true,
            'is_active' => true,
        ]);

        CustomerTaxProfile::create([
            'customer_id' => $customer->id,
            'identification_document_id' => $identificationDocument->id,
            'identification' => $invoiceReference === 'INV-001' ? '123456789' : '987654321',
            'legal_organization_id' => $legalOrganization->id,
            'tribute_id' => 18,
            'municipality_id' => $municipality->factus_id,
            'names' => $customerName,
        ]);

        DianPaymentMethod::firstOrCreate([
            'code' => '10',
        ], [
            'name' => 'Efectivo',
        ]);

        $invoiceRange = FactusNumberingRange::create([
            'factus_id' => $invoiceReference === 'INV-001' ? 1274 : 2274,
            'document' => 'Factura de Venta',
            'prefix' => 'SETP',
            'range_from' => 990000000,
            'range_to' => 995000000,
            'current' => 990000001,
            'start_date' => now()->subDay(),
            'end_date' => now()->addYear(),
            'is_expired' => false,
            'is_active' => true,
        ]);

        $creditNoteRange = FactusNumberingRange::create([
            'factus_id' => $invoiceReference === 'INV-001' ? 1275 : 2275,
            'document' => "Nota Cr\xC3\xA9dito",
            'prefix' => 'NC',
            'range_from' => 1,
            'range_to' => 999999,
            'current' => 76,
            'start_date' => now()->subDay(),
            'end_date' => now()->addYear(),
            'is_expired' => false,
            'is_active' => true,
        ]);

        $invoice = ElectronicInvoice::create([
            'customer_id' => $customer->id,
            'factus_numbering_range_id' => $invoiceRange->factus_id,
            'factus_bill_id' => 514,
            'document_type_id' => 1,
            'operation_type_id' => 1,
            'payment_method_code' => '10',
            'payment_form_code' => '1',
            'reference_code' => $invoiceReference,
            'document' => $invoiceDocument,
            'status' => 'accepted',
            'cufe' => $invoiceCufe,
            'gross_value' => 100000,
            'tax_amount' => 19000,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'total' => 119000,
            'response_dian' => [],
        ]);

        ElectronicInvoiceItem::create([
            'electronic_invoice_id' => $invoice->id,
            'tribute_id' => 18,
            'standard_code_id' => 1,
            'unit_measure_id' => 70,
            'code_reference' => $itemCodeReference,
            'name' => $itemName,
            'quantity' => 1,
            'price' => 100000,
            'tax_rate' => 19,
            'tax_amount' => 19000,
            'discount_rate' => 0,
            'is_excluded' => false,
            'total' => 119000,
        ]);

        return [$invoice, $creditNoteRange];
    }

    private function seedCatalogs(): void
    {
        DB::table('dian_customer_tributes')->insert([
            'id' => 18,
            'code' => '01',
            'name' => 'IVA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('dian_product_standards')->insert([
            'id' => 1,
            'name' => 'Estandar de adopcion del contribuyente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DianMeasurementUnit::create([
            'factus_id' => 70,
            'code' => '94',
            'name' => 'unidad',
        ]);
    }

    private function createTestingSchema(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_electronic_invoice')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('dian_identification_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->boolean('requires_dv')->default(false);
            $table->timestamps();
        });

        Schema::create('dian_legal_organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('dian_customer_tributes', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('dian_municipalities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('factus_id')->unique();
            $table->string('code');
            $table->string('name');
            $table->string('department');
            $table->timestamps();
        });

        Schema::create('customer_tax_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('identification_document_id')->nullable();
            $table->string('identification')->nullable();
            $table->integer('dv')->nullable();
            $table->foreignId('legal_organization_id')->nullable();
            $table->string('company')->nullable();
            $table->string('trade_name')->nullable();
            $table->string('names')->nullable();
            $table->string('address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->foreignId('tribute_id')->nullable();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->timestamps();
        });

        Schema::create('dian_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('dian_product_standards', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('dian_measurement_units', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('factus_id')->unique();
            $table->string('code')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('factus_numbering_ranges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('factus_id')->unique();
            $table->string('document');
            $table->string('document_code')->nullable();
            $table->string('prefix');
            $table->unsignedBigInteger('range_from');
            $table->unsignedBigInteger('range_to');
            $table->unsignedBigInteger('current');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_expired')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('electronic_invoices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('factus_numbering_range_id')->nullable();
            $table->unsignedBigInteger('factus_bill_id')->nullable();
            $table->unsignedBigInteger('document_type_id');
            $table->unsignedBigInteger('operation_type_id');
            $table->string('payment_method_code', 10)->nullable();
            $table->string('payment_form_code', 10)->nullable();
            $table->string('reference_code')->unique();
            $table->string('document');
            $table->string('status')->default('pending');
            $table->string('cufe')->nullable();
            $table->text('qr')->nullable();
            $table->decimal('total', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('gross_value', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('surcharge_amount', 15, 2)->default(0);
            $table->json('response_dian')->nullable();
            $table->timestamps();
        });

        Schema::create('electronic_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('electronic_invoice_id');
            $table->unsignedBigInteger('tribute_id')->nullable();
            $table->unsignedBigInteger('standard_code_id')->nullable();
            $table->unsignedBigInteger('unit_measure_id');
            $table->string('code_reference')->nullable();
            $table->string('name');
            $table->decimal('quantity', 10, 3);
            $table->decimal('price', 15, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_rate', 5, 2)->default(0);
            $table->boolean('is_excluded')->default(false);
            $table->decimal('total', 15, 2);
            $table->timestamps();
        });

        Schema::create('electronic_credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('electronic_invoice_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('factus_numbering_range_id')->nullable();
            $table->unsignedBigInteger('referenced_factus_bill_id');
            $table->unsignedBigInteger('factus_credit_note_id')->nullable();
            $table->unsignedTinyInteger('correction_concept_code');
            $table->unsignedTinyInteger('customization_id')->default(20);
            $table->string('payment_method_code', 10);
            $table->boolean('send_email')->default(true);
            $table->string('reference_code')->unique();
            $table->string('document');
            $table->string('status')->default('pending');
            $table->string('cude')->nullable();
            $table->text('qr')->nullable();
            $table->decimal('total', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('gross_value', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('surcharge_amount', 15, 2)->default(0);
            $table->string('notes', 250)->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->json('payload_sent')->nullable();
            $table->json('response_dian')->nullable();
            $table->timestamps();
        });

        Schema::create('electronic_credit_note_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('electronic_credit_note_id');
            $table->unsignedBigInteger('tribute_id')->nullable();
            $table->unsignedBigInteger('standard_code_id')->nullable();
            $table->unsignedBigInteger('unit_measure_id');
            $table->string('code_reference');
            $table->string('name');
            $table->text('note')->nullable();
            $table->decimal('quantity', 10, 3);
            $table->decimal('price', 15, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_rate', 5, 2)->default(0);
            $table->boolean('is_excluded')->default(false);
            $table->decimal('total', 15, 2);
            $table->timestamps();
        });
    }
}
