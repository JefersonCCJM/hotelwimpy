<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_quick_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->date('operational_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['room_id', 'operational_date'], 'room_quick_reservations_room_date_unique');
            $table->index(['operational_date', 'room_id'], 'room_quick_reservations_date_room_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_quick_reservations');
    }
};

