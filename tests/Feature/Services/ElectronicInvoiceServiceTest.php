<?php

namespace Tests\Feature\Services;

use App\Models\CompanyTaxSetting;
use App\Models\Customer;
use App\Models\CustomerTaxProfile;
use App\Models\DianDocumentType;
use App\Models\DianIdentificationDocument;
use App\Models\DianLegalOrganization;
use App\Models\DianMeasurementUnit;
use App\Models\DianMunicipality;
use App\Models\DianOperationType;
use App\Models\DianPaymentForm;
use App\Models\DianPaymentMethod;
use App\Models\ElectronicInvoice;
use App\Models\FactusNumberingRange;
use App\Services\ElectronicInvoiceService;
use App\Services\FactusApiService;
use App\Services\FactusNumberingRangeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ElectronicInvoiceServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestingSchema();
    }

    #[Test]
    public function it_persists_notes_and_sends_them_as_observation_to_factus(): void
    {
        $this->seedInvoiceCatalogs();

        $municipality = DianMunicipality::create([
            'factus_id' => 980,
            'code' => '68001',
            'name' => 'San Gil',
            'department' => 'Santander',
        ]);

        CompanyTaxSetting::create([
            'company_name' => 'Hotel San Pedro',
            'nit' => '900123456',
            'dv' => '7',
            'email' => 'facturacion@hotelsanpedro.test',
            'municipality_id' => $municipality->factus_id,
            'economic_activity' => '5511',
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
            'name' => 'Alan Turing',
            'email' => 'alan@example.com',
            'phone' => '3001234567',
            'address' => 'Calle 123',
            'requires_electronic_invoice' => true,
            'is_active' => true,
        ]);

        CustomerTaxProfile::create([
            'customer_id' => $customer->id,
            'identification_document_id' => $identificationDocument->id,
            'identification' => '123456789',
            'legal_organization_id' => $legalOrganization->id,
            'tribute_id' => 18,
            'municipality_id' => $municipality->factus_id,
            'names' => 'Alan Turing',
            'email' => $customer->email,
            'phone' => $customer->phone,
            'address' => $customer->address,
        ]);

        $documentType = DianDocumentType::create([
            'code' => '01',
            'name' => 'Factura electronica de venta',
        ]);

        $operationType = DianOperationType::create([
            'code' => '10',
            'name' => 'Estandar',
        ]);

        DianPaymentMethod::create([
            'code' => '10',
            'name' => 'Efectivo',
        ]);

        DianPaymentForm::create([
            'code' => '1',
            'name' => 'Pago de contado',
        ]);

        $numberingRange = FactusNumberingRange::create([
            'factus_id' => 4,
            'document' => 'Factura de Venta',
            'document_code' => '01',
            'prefix' => 'SETP',
            'range_from' => 990000000,
            'range_to' => 995000000,
            'current' => 990000001,
            'resolution_number' => '18760000001',
            'technical_key' => 'abc123',
            'start_date' => now()->subDay(),
            'end_date' => now()->addYear(),
            'is_expired' => false,
            'is_active' => true,
        ]);

        $capturedPayload = null;

        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('get')
            ->once()
            ->with('/v1/company')
            ->andReturn([
                'data' => [
                    'company' => 'Hotel San Pedro',
                    'address' => 'Cra 10 # 9-04',
                    'phone' => '3001234567',
                    'email' => 'facturacion@hotelsanpedro.test',
                    'municipality' => [
                        'code' => $municipality->code,
                        'name' => $municipality->name,
                    ],
                ],
            ]);

        $factusApi->shouldReceive('post')
            ->once()
            ->with('/v1/bills/validate', Mockery::on(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return true;
            }))
            ->andReturn([
                'data' => [
                    'bill' => [
                        'id' => 514,
                        'status' => 'accepted',
                        'cufe' => 'CUFE-123456',
                        'qr' => 'https://factus.test/qr/123456',
                        'number' => 'SETP990000001',
                    ],
                ],
            ]);

        $service = new ElectronicInvoiceService(
            $factusApi,
            Mockery::mock(FactusNumberingRangeService::class)
        );

        $invoice = $service->createFromForm([
            'customer_id' => $customer->id,
            'document_type_id' => $documentType->id,
            'operation_type_id' => $operationType->id,
            'payment_method_code' => '10',
            'payment_form_code' => '1',
            'numbering_range_id' => $numberingRange->id,
            'reference_code' => 'OBS-FACTUS-001',
            'notes' => '  Observacion enviada a Factus  ',
            'items' => [
                [
                    'name' => 'Hospedaje una noche',
                    'quantity' => 1,
                    'price' => 100000,
                    'tax_rate' => 19,
                    'tax' => 19000,
                    'total' => 119000,
                ],
            ],
            'totals' => [
                'subtotal' => 100000,
                'tax' => 19000,
                'total' => 119000,
            ],
        ]);

        $storedInvoice = $invoice->fresh();

        $this->assertSame('Observacion enviada a Factus', $storedInvoice->notes);
        $this->assertSame('accepted', $storedInvoice->status);
        $this->assertSame(514, $storedInvoice->factus_bill_id);
        $this->assertNotNull($capturedPayload);
        $this->assertSame('Observacion enviada a Factus', $capturedPayload['observation'] ?? null);
        $this->assertSame(
            'Observacion enviada a Factus',
            $storedInvoice->payload_sent['observation'] ?? null
        );
    }

    #[Test]
    public function it_marks_the_invoice_as_rejected_and_cleans_the_hidden_factus_pending_document_after_a_422(): void
    {
        $this->seedInvoiceCatalogs();

        $municipality = DianMunicipality::create([
            'factus_id' => 980,
            'code' => '68001',
            'name' => 'San Gil',
            'department' => 'Santander',
        ]);

        CompanyTaxSetting::create([
            'company_name' => 'Hotel San Pedro',
            'nit' => '900123456',
            'dv' => '7',
            'email' => 'facturacion@hotelsanpedro.test',
            'municipality_id' => $municipality->factus_id,
            'economic_activity' => '5511',
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
            'name' => 'Alan Turing',
            'email' => 'alan@example.com',
            'phone' => '3001234567',
            'address' => 'Calle 123',
            'requires_electronic_invoice' => true,
            'is_active' => true,
        ]);

        CustomerTaxProfile::create([
            'customer_id' => $customer->id,
            'identification_document_id' => $identificationDocument->id,
            'identification' => '123456789',
            'legal_organization_id' => $legalOrganization->id,
            'tribute_id' => 18,
            'municipality_id' => $municipality->factus_id,
            'names' => 'Alan Turing',
            'email' => $customer->email,
            'phone' => $customer->phone,
            'address' => $customer->address,
        ]);

        $documentType = DianDocumentType::create([
            'code' => '01',
            'name' => 'Factura electronica de venta',
        ]);

        $operationType = DianOperationType::create([
            'code' => '10',
            'name' => 'Estandar',
        ]);

        DianPaymentMethod::create([
            'code' => '10',
            'name' => 'Efectivo',
        ]);

        DianPaymentForm::create([
            'code' => '1',
            'name' => 'Pago de contado',
        ]);

        $numberingRange = FactusNumberingRange::create([
            'factus_id' => 4,
            'document' => 'Factura de Venta',
            'document_code' => '01',
            'prefix' => 'SETP',
            'range_from' => 990000000,
            'range_to' => 995000000,
            'current' => 990000001,
            'resolution_number' => '18760000001',
            'technical_key' => 'abc123',
            'start_date' => now()->subDay(),
            'end_date' => now()->addYear(),
            'is_expired' => false,
            'is_active' => true,
        ]);

        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('get')
            ->once()
            ->with('/v1/company')
            ->andReturn([
                'data' => [
                    'company' => 'Hotel San Pedro',
                    'address' => 'Cra 10 # 9-04',
                    'phone' => '3001234567',
                    'email' => 'facturacion@hotelsanpedro.test',
                    'municipality' => [
                        'code' => $municipality->code,
                        'name' => $municipality->name,
                    ],
                ],
            ]);

        $factusApi->shouldReceive('post')
            ->once()
            ->with('/v1/bills/validate', Mockery::type('array'))
            ->andThrow(new \App\Exceptions\FactusApiException(
                'Error en Factus API (post /v1/bills/validate): El documento contiene errores de validación',
                422,
                [
                    'status' => 'Validation error',
                    'message' => 'El documento contiene errores de validación',
                    'data' => [
                        'errors' => [
                            'FAK24' => 'Regla: FAK24, Rechazo: No está informado el DV del NIT',
                        ],
                    ],
                ]
            ));

        $factusApi->shouldReceive('deleteBillByReference')
            ->once()
            ->with('OBS-FACTUS-422')
            ->andReturn([
                'status' => 'OK',
                'message' => 'Documento eliminado con éxito',
            ]);

        $service = new ElectronicInvoiceService(
            $factusApi,
            Mockery::mock(FactusNumberingRangeService::class)
        );

        try {
            $service->createFromForm([
                'customer_id' => $customer->id,
                'document_type_id' => $documentType->id,
                'operation_type_id' => $operationType->id,
                'payment_method_code' => '10',
                'payment_form_code' => '1',
                'numbering_range_id' => $numberingRange->id,
                'reference_code' => 'OBS-FACTUS-422',
                'notes' => 'Documento con error',
                'items' => [
                    [
                        'name' => 'Hospedaje una noche',
                        'quantity' => 1,
                        'price' => 100000,
                        'tax_rate' => 19,
                        'tax' => 19000,
                        'total' => 119000,
                    ],
                ],
                'totals' => [
                    'subtotal' => 100000,
                    'tax' => 19000,
                    'total' => 119000,
                ],
            ]);

            $this->fail('Se esperaba una excepción de Factus.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('422', $exception->getMessage());
            $this->assertStringContainsString('FAK24', $exception->getMessage());
            $this->assertStringContainsString('pendiente oculto', $exception->getMessage());
        }

        $storedInvoice = ElectronicInvoice::query()->where('reference_code', 'OBS-FACTUS-422')->firstOrFail();

        $this->assertSame('rejected', $storedInvoice->status);
        $this->assertSame('OBS-FACTUS-422', $storedInvoice->payload_sent['reference_code'] ?? null);
        $this->assertSame(
            'Regla: FAK24, Rechazo: No está informado el DV del NIT',
            data_get($storedInvoice->response_dian, 'data.errors.FAK24')
        );
        $this->assertTrue(
            (bool) data_get($storedInvoice->response_dian, '_cleanup.current_reference_cleanup.success')
        );
    }

    private function seedInvoiceCatalogs(): void
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
            $table->text('notes')->nullable();
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

        Schema::create('dian_document_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('dian_operation_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('dian_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('dian_payment_forms', function (Blueprint $table): void {
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
            $table->string('resolution_number')->nullable();
            $table->string('technical_key')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_expired')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('company_tax_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('company_name')->nullable();
            $table->string('nit')->nullable();
            $table->string('dv')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->string('economic_activity')->nullable();
            $table->unsignedBigInteger('factus_company_id')->nullable();
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
            $table->string('notes', 250)->nullable();
            $table->string('status')->default('pending');
            $table->string('cufe')->nullable();
            $table->text('qr')->nullable();
            $table->decimal('total', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('gross_value', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('surcharge_amount', 15, 2)->default(0);
            $table->timestamp('validated_at')->nullable();
            $table->json('payload_sent')->nullable();
            $table->json('response_dian')->nullable();
            $table->string('pdf_url')->nullable();
            $table->string('xml_url')->nullable();
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
    }
}
