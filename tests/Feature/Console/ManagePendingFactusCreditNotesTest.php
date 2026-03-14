<?php

namespace Tests\Feature\Console;

use App\Exceptions\FactusApiException;
use App\Models\Customer;
use App\Models\ElectronicCreditNote;
use App\Models\ElectronicInvoice;
use App\Models\FactusNumberingRange;
use App\Services\FactusApiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManagePendingFactusCreditNotesTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestingSchema();
    }

    #[Test]
    public function it_lists_pending_and_rejected_credit_notes(): void
    {
        $this->createCreditNote(status: 'pending', referenceCode: 'NC-PENDING-001', document: 'NC1');
        $this->createCreditNote(status: 'rejected', referenceCode: 'NC-REJECTED-001', document: 'NC2');
        $this->createCreditNote(status: 'accepted', referenceCode: 'NC-ACCEPTED-001', document: 'NC3');

        $this->artisan('factus:credit-notes-pending', [
            '--limit' => 10,
        ])
            ->expectsOutputToContain('NC-PENDING-001')
            ->expectsOutputToContain('NC-REJECTED-001')
            ->doesntExpectOutputToContain('NC-ACCEPTED-001')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_cleans_a_pending_credit_note_by_reference_and_marks_it_cancelled_locally(): void
    {
        $creditNote = $this->createCreditNote(status: 'pending', referenceCode: 'NC-CLEAN-001', document: 'NC4');

        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('deleteCreditNoteByReference')
            ->once()
            ->with('NC-CLEAN-001')
            ->andReturn([
                'status' => 'OK',
                'message' => 'Documento eliminado con exito',
            ]);

        $this->app->instance(FactusApiService::class, $factusApi);

        $this->artisan('factus:credit-notes-pending', [
            'reference-codes' => ['NC-CLEAN-001'],
            '--cleanup' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('NC-CLEAN-001')
            ->assertExitCode(0);

        $creditNote->refresh();

        $this->assertSame('cancelled', $creditNote->status);
        $this->assertSame(
            'Documento eliminado con exito',
            data_get($creditNote->response_dian, 'manual_cleanup_response.message')
        );
    }

    #[Test]
    public function it_marks_local_credit_notes_as_cancelled_when_the_reference_is_missing_in_factus(): void
    {
        $creditNote = $this->createCreditNote(status: 'rejected', referenceCode: 'NC-MISSING-001', document: 'NC5');

        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('deleteCreditNoteByReference')
            ->once()
            ->with('NC-MISSING-001')
            ->andThrow(new FactusApiException(
                'No encontrada',
                404,
                ['message' => 'Documento no encontrado']
            ));

        $this->app->instance(FactusApiService::class, $factusApi);

        $this->artisan('factus:credit-notes-pending', [
            'reference-codes' => ['NC-MISSING-001'],
            '--cleanup' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('NC-MISSING-001')
            ->assertExitCode(0);

        $creditNote->refresh();

        $this->assertSame('cancelled', $creditNote->status);
        $this->assertSame('not_found_in_factus', data_get($creditNote->response_dian, 'manual_cleanup_reason'));
    }

    #[Test]
    public function it_lists_remote_credit_notes_from_factus(): void
    {
        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('getCreditNotes')
            ->once()
            ->with([], 1, 50)
            ->andReturn([
                'data' => [
                    'data' => [
                        [
                            'reference_code' => 'NC-REMOTE-001',
                            'number' => 'NC44',
                            'status' => 0,
                            'names' => 'Cliente remoto',
                            'total' => '71400.00',
                        ],
                    ],
                ],
            ]);

        $this->app->instance(FactusApiService::class, $factusApi);

        $this->artisan('factus:credit-notes-pending', [
            '--remote' => true,
        ])
            ->expectsOutputToContain('NC-REMOTE-001')
            ->assertExitCode(0);
    }

    private function createCreditNote(string $status, string $referenceCode, string $document): ElectronicCreditNote
    {
        $customer = Customer::query()->create([
            'name' => 'Cliente ' . $referenceCode,
            'email' => strtolower($referenceCode) . '@example.com',
            'is_active' => true,
            'requires_electronic_invoice' => true,
        ]);

        $range = FactusNumberingRange::query()->create([
            'factus_id' => random_int(1000, 9999),
            'document' => 'Nota Credito',
            'document_code' => FactusNumberingRange::CREDIT_NOTE_DOCUMENT_CODE,
            'prefix' => 'NC',
            'range_from' => 1,
            'range_to' => 999999,
            'current' => 10,
            'is_active' => true,
            'is_expired' => false,
        ]);

        $invoice = ElectronicInvoice::query()->create([
            'customer_id' => $customer->id,
            'factus_numbering_range_id' => $range->factus_id,
            'payment_method_code' => '10',
            'payment_form_code' => '1',
            'reference_code' => 'INV-' . $referenceCode,
            'document' => 'SETP-' . $referenceCode,
            'status' => 'accepted',
            'gross_value' => 100000,
            'tax_amount' => 19000,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'total' => 119000,
        ]);

        return ElectronicCreditNote::query()->create([
            'electronic_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'factus_numbering_range_id' => $range->factus_id,
            'referenced_factus_bill_id' => 514,
            'correction_concept_code' => 2,
            'customization_id' => 20,
            'payment_method_code' => '10',
            'send_email' => true,
            'reference_code' => $referenceCode,
            'document' => $document,
            'status' => $status,
            'gross_value' => 100000,
            'tax_amount' => 19000,
            'discount_amount' => 0,
            'surcharge_amount' => 0,
            'total' => 119000,
            'response_dian' => [],
        ]);
    }

    private function createTestingSchema(): void
    {
        Schema::dropIfExists('electronic_credit_notes');
        Schema::dropIfExists('electronic_invoices');
        Schema::dropIfExists('factus_numbering_ranges');
        Schema::dropIfExists('customers');

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_electronic_invoice')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('factus_numbering_ranges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('factus_id')->unique();
            $table->string('document')->nullable();
            $table->string('document_code')->nullable();
            $table->string('prefix')->nullable();
            $table->unsignedBigInteger('range_from')->nullable();
            $table->unsignedBigInteger('range_to')->nullable();
            $table->unsignedBigInteger('current')->nullable();
            $table->string('resolution_number')->nullable();
            $table->text('technical_key')->nullable();
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
            $table->unsignedBigInteger('document_type_id')->nullable();
            $table->unsignedBigInteger('operation_type_id')->nullable();
            $table->string('payment_method_code')->nullable();
            $table->string('payment_form_code')->nullable();
            $table->string('reference_code')->nullable();
            $table->string('document')->nullable();
            $table->string('status')->default('pending');
            $table->string('cufe')->nullable();
            $table->string('qr')->nullable();
            $table->decimal('gross_value', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('surcharge_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->json('payload_sent')->nullable();
            $table->json('response_dian')->nullable();
            $table->timestamps();
        });

        Schema::create('electronic_credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('electronic_invoice_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('factus_numbering_range_id')->nullable();
            $table->unsignedBigInteger('referenced_factus_bill_id')->nullable();
            $table->unsignedBigInteger('factus_credit_note_id')->nullable();
            $table->unsignedInteger('correction_concept_code');
            $table->unsignedInteger('customization_id')->default(20);
            $table->string('payment_method_code');
            $table->boolean('send_email')->default(true);
            $table->string('reference_code')->nullable();
            $table->string('document')->nullable();
            $table->string('status')->default('pending');
            $table->string('cude')->nullable();
            $table->text('qr')->nullable();
            $table->decimal('gross_value', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('surcharge_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->json('payload_sent')->nullable();
            $table->json('response_dian')->nullable();
            $table->timestamps();
        });
    }
}
