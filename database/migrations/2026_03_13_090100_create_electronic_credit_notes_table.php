<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('electronic_invoice_id')->constrained('electronic_invoices')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('factus_numbering_range_id')->nullable();
            $table->unsignedBigInteger('referenced_factus_bill_id');
            $table->unsignedBigInteger('factus_credit_note_id')->nullable()->unique();
            $table->unsignedTinyInteger('correction_concept_code');
            $table->unsignedTinyInteger('customization_id')->default(20);
            $table->string('payment_method_code', 10);
            $table->boolean('send_email')->default(true);
            $table->string('reference_code')->unique();
            $table->string('document');
            $table->string('status')->default('pending');
            $table->string('cude')->nullable()->unique();
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

            $table->foreign('payment_method_code')
                ->references('code')
                ->on('dian_payment_methods')
                ->restrictOnDelete();

            $table->index('electronic_invoice_id');
            $table->index('customer_id');
            $table->index('factus_numbering_range_id');
            $table->index('referenced_factus_bill_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_credit_notes');
    }
};
