<?php

namespace Tests\Feature;

use App\Models\CompanyTaxSetting;
use App\Models\Customer;
use App\Models\CustomerTaxProfile;
use App\Models\DianDocumentType;
use App\Models\DianIdentificationDocument;
use App\Models\DianLegalOrganization;
use App\Models\DianMunicipality;
use App\Models\DianOperationType;
use App\Models\DianPaymentForm;
use App\Models\DianPaymentMethod;
use App\Models\DianProductStandard;
use App\Models\FactusNumberingRange;
use App\Services\ElectronicInvoiceService;
use App\Services\FactusApiService;
use App\Services\FactusNumberingRangeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class ElectronicInvoiceServiceObservationTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.default' => 'errorlog']);

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_electronic_invoice')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('dian_municipalities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('factus_id')->unique();
            $table->string('code', 10)->nullable();
            $table->string('name');
            $table->string('department');
            $table->timestamps();
        });

        Schema::create('dian_identification_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->nullable();
            $table->string('name');
            $table->boolean('requires_dv')->default(false);
            $table->timestamps();
        });

        Schema::create('dian_legal_organizations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('dian_customer_tributes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10);
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('customer_tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->unique();
            $table->unsignedBigInteger('identification_document_id');
            $table->string('identification', 20);
            $table->integer('dv')->nullable();
            $table->unsignedBigInteger('legal_organization_id')->nullable();
            $table->string('company')->nullable();
            $table->string('trade_name')->nullable();
            $table->string('names')->nullable();
            $table->string('address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->unsignedBigInteger('tribute_id')->nullable();
            $table->unsignedBigInteger('municipality_id');
            $table->timestamps();
        });

        Schema::create('company_tax_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->nullable();
            $table->string('nit')->nullable();
            $table->string('dv')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('municipality_id')->nullable();
            $table->string('economic_activity')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('factus_company_id')->nullable();
            $table->timestamps();
        });

        Schema::create('dian_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('dian_operation_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('dian_payment_methods', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('dian_payment_forms', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('factus_numbering_ranges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('factus_id')->unique();
            $table->string('document')->nullable();
            $table->string('document_code')->nullable();
            $table->string('prefix')->nullable();
            $table->unsignedBigInteger('range_from')->nullable();
            $table->unsignedBigInteger('range_to')->nullable();
            $table->unsignedBigInteger('current')->nullable();
            $table->string('resolution_number')->nullable();
            $table->string('technical_key')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_expired')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('electronic_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('factus_numbering_range_id')->nullable();
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

        Schema::create('dian_product_standards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('electronic_invoice_items', function (Blueprint $table) {
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

    public function test_create_from_form_persists_and_sends_invoice_observation(): void
    {
        $municipality = DianMunicipality::create([
            'factus_id' => 980,
            'code' => '05001',
            'name' => 'Medellin',
            'department' => 'Antioquia',
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
            'name' => 'Contado',
        ]);

        DianProductStandard::create([
            'name' => 'Estandar',
        ]);

        DB::table('dian_customer_tributes')->insert([
            'id' => 18,
            'code' => '01',
            'name' => 'IVA',
            'created_at' => now(),
            'updated_at' => now(),
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
            'phone' => '3000000000',
            'address' => 'Calle 1 # 2-3',
            'is_active' => true,
            'requires_electronic_invoice' => true,
        ]);

        CustomerTaxProfile::create([
            'customer_id' => $customer->id,
            'identification_document_id' => $identificationDocument->id,
            'identification' => '123456789',
            'legal_organization_id' => $legalOrganization->id,
            'tribute_id' => 18,
            'municipality_id' => $municipality->factus_id,
        ]);

        CompanyTaxSetting::create([
            'company_name' => 'Hotel Wimpy',
            'nit' => '900123456',
            'dv' => '7',
            'email' => 'facturacion@hotelwimpy.test',
            'municipality_id' => $municipality->factus_id,
        ]);

        $numberingRange = FactusNumberingRange::create([
            'factus_id' => 4,
            'document' => 'Factura de Venta',
            'prefix' => 'SETP',
            'range_from' => 1,
            'range_to' => 999999,
            'current' => 2300,
            'resolution_number' => '18760000001',
            'technical_key' => 'abc123',
            'start_date' => now()->subDay(),
            'end_date' => now()->addYear(),
            'is_expired' => false,
            'is_active' => true,
        ]);

        $capturedPayload = null;

        $apiService = Mockery::mock(FactusApiService::class);
        $apiService->shouldReceive('get')
            ->once()
            ->with('/v1/company')
            ->andReturn([
                'data' => [
                    'company' => 'Hotel Wimpy',
                    'address' => 'Calle 8 # 20-25',
                    'phone' => '3103250011',
                    'email' => 'facturacion@hotelwimpy.test',
                    'municipality' => [
                        'code' => $municipality->code,
                        'name' => $municipality->name,
                    ],
                ],
            ]);

        $apiService->shouldReceive('post')
            ->once()
            ->with('/v1/bills/validate', Mockery::on(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return ($payload['observation'] ?? null) === 'Observacion enviada a Factus';
            }))
            ->andReturn([
                'data' => [
                    'bill' => [
                        'status' => 'accepted',
                        'cufe' => 'cufe-demo',
                        'number' => 'SETP2300',
                    ],
                ],
            ]);

        $service = new ElectronicInvoiceService(
            $apiService,
            Mockery::mock(FactusNumberingRangeService::class)
        );

        $invoice = $service->createFromForm([
            'customer_id' => $customer->id,
            'document_type_id' => $documentType->id,
            'operation_type_id' => $operationType->id,
            'payment_method_code' => '10',
            'payment_form_code' => '1',
            'numbering_range_id' => $numberingRange->id,
            'notes' => '  Observacion enviada a Factus  ',
            'items' => [
                [
                    'name' => 'Servicio hotel',
                    'quantity' => 2,
                    'price' => 50000,
                    'tax_rate' => 0,
                    'tax' => 0,
                    'total' => 100000,
                ],
            ],
            'totals' => [
                'subtotal' => 100000,
                'tax' => 0,
                'total' => 100000,
            ],
        ]);

        $storedInvoice = $invoice->fresh();

        $this->assertSame('Observacion enviada a Factus', $storedInvoice->notes);
        $this->assertSame('Observacion enviada a Factus', $capturedPayload['observation'] ?? null);
        $this->assertSame('Observacion enviada a Factus', data_get($storedInvoice->payload_sent, 'observation'));
    }
}
