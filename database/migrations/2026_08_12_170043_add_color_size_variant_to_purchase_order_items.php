<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            foreach (['color_id', 'size_id', 'product_variant_id'] as $column) {
                if (!Schema::hasColumn('purchase_order_items', $column)) {
                    $table->unsignedBigInteger($column)->nullable()->after('product_id');
                }

                if (!Schema::hasIndex('purchase_order_items', [$column])) {
                    $table->index($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['color_id', 'size_id', 'product_variant_id'],
                fn (string $column) => Schema::hasColumn('purchase_order_items', $column)
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};