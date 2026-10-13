<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_invoice_items', 'purchase_return_items'] as $tableName) {
            if (!Schema::hasColumn($tableName, 'product_unit_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    // This column stores the selected unit ID, matching the API's unit_id.
                    $table->unsignedBigInteger('product_unit_id')->nullable()->after('product_id');
                    $table->index('product_unit_id');
                });
            }
        }
    }

    public function down(): void
    {
        // Keep this compatibility column in place: it may have existed before
        // this migration on databases upgraded from older deployments.
    }
};
