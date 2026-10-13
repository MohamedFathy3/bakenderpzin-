<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sales_invoice_return_items', 'sales_invoice_item_id')) {
            Schema::table('sales_invoice_return_items', function (Blueprint $table) {
                $table->unsignedBigInteger('sales_invoice_item_id')->nullable()->after('sales_invoice_return_id')->index();
            });
        }
        if (!Schema::hasColumn('sales_invoice_return_items', 'product_unit_id')) {
            Schema::table('sales_invoice_return_items', function (Blueprint $table) {
                $table->unsignedBigInteger('product_unit_id')->nullable()->after('product_id')->index();
            });
        }
        if (!Schema::hasColumn('sales_invoice_return_items', 'color_id')) {
            Schema::table('sales_invoice_return_items', function (Blueprint $table) {
                $table->unsignedBigInteger('color_id')->nullable()->after('product_unit_id')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::table('sales_invoice_return_items', function (Blueprint $table) {
            foreach (['sales_invoice_item_id', 'product_unit_id', 'color_id'] as $column) {
                if (Schema::hasColumn('sales_invoice_return_items', $column)) {
                    $table->dropIndex([$column]);
                    $table->dropColumn($column);
                }
            }
        });
    }
};
