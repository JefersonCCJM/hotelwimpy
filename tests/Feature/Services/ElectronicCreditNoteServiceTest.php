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
