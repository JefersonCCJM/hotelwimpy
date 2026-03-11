<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('electronic_invoices', 'notes')) {
            return;
        }

        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->string('notes', 250)->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('electronic_invoices', 'notes')) {
            return;
        }

        Schema::table('electronic_invoices', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
