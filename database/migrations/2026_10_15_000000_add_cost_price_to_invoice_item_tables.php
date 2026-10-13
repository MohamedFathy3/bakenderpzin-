<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoice_items', 'sales_invoice_items'] as $tableName) {
            if (!Schema::hasColumn($tableName, 'cost_price')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->decimal('cost_price', 12, 2)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['invoice_items', 'sales_invoice_items'] as $tableName) {
            if (Schema::hasColumn($tableName, 'cost_price')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('cost_price');
                });
            }
        }
    }
};
