<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('room_operational_statuses')) {
            return;
        }

        Schema::create('room_operational_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->date('operational_date');
            $table->text('observation')->nullable();
            $table->string('cleaning_override_status', 40)->nullable();
            $table->date('maintenance_source_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['room_id', 'operational_date'], 'room_operational_statuses_room_date_unique');
            $table->index(['operational_date', 'cleaning_override_status'], 'room_operational_statuses_date_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_operational_statuses');
    }
};
