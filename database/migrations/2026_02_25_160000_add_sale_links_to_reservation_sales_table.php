<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_sales', function (Blueprint $table) {
            if (!Schema::hasColumn('reservation_sales', 'sale_id')) {
                $table->foreignId('sale_id')
                    ->nullable()
                    ->after('reservation_id')
                    ->constrained('sales')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('reservation_sales', 'sale_item_id')) {
                $table->foreignId('sale_item_id')
                    ->nullable()
                    ->after('sale_id')
                    ->constrained('sale_items')
                    ->nullOnDelete();
            }
        });

        if (
            Schema::hasColumn('reservation_sales', 'sale_item_id')
            && !Schema::hasIndex('reservation_sales', 'reservation_sales_sale_item_id_unique')
        ) {
            Schema::table('reservation_sales', function (Blueprint $table) {
                $table->unique('sale_item_id', 'reservation_sales_sale_item_id_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('reservation_sales', function (Blueprint $table) {
            if (Schema::hasIndex('reservation_sales', 'reservation_sales_sale_item_id_unique')) {
                $table->dropUnique('reservation_sales_sale_item_id_unique');
            }

            if (Schema::hasColumn('reservation_sales', 'sale_item_id')) {
                $table->dropConstrainedForeignId('sale_item_id');
            }

            if (Schema::hasColumn('reservation_sales', 'sale_id')) {
                $table->dropConstrainedForeignId('sale_id');
            }
        });
    }
};
