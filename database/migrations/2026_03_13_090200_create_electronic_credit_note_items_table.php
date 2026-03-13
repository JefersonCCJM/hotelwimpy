<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_credit_note_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('electronic_credit_note_id')->constrained('electronic_credit_notes')->cascadeOnDelete();
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

            $table->index('electronic_credit_note_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_credit_note_items');
    }
};
