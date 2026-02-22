<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('external_incomes')) {
            Schema::create('external_incomes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('shift_handover_id')->nullable()->constrained('shift_handovers')->nullOnDelete();
                $table->enum('payment_method', ['efectivo', 'transferencia'])->default('efectivo');
                $table->date('income_date');
                $table->decimal('amount', 12, 2);
                $table->string('reason', 180);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['income_date', 'payment_method']);
                $table->index('shift_handover_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('external_incomes');
    }
};
