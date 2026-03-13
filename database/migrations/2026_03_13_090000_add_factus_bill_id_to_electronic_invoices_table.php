<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('electronic_invoices', 'factus_bill_id')) {
            Schema::table('electronic_invoices', function (Blueprint $table): void {
                $table->unsignedBigInteger('factus_bill_id')
                    ->nullable()
                    ->after('factus_numbering_range_id');

                $table->index('factus_bill_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('electronic_invoices', 'factus_bill_id')) {
            Schema::table('electronic_invoices', function (Blueprint $table): void {
                $table->dropIndex(['factus_bill_id']);
                $table->dropColumn('factus_bill_id');
            });
        }
    }
};
