<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_return_items', function (Blueprint $table) {
            // ✅ إضافة الأعمدة الناقصة
            if (!Schema::hasColumn('purchase_return_items', 'color_id')) {
                $table->unsignedBigInteger('color_id')->nullable()->after('product_unit_id');
            }
            
            // if (!Schema::hasColumn('purchase_return_items', 'size_id')) {
            //     $table->unsignedBigInteger('size_id')->nullable()->after('color_id');
            // }
            
            if (!Schema::hasColumn('purchase_return_items', 'product_variant_id')) {
                $table->unsignedBigInteger('product_variant_id')->nullable()->after('color_id');
            }
            
            // ✅ إضافة indices
            if (Schema::hasColumn('purchase_return_items', 'color_id')) {
                $table->index('color_id');
            }
            if (Schema::hasColumn('purchase_return_items', 'size_id')) {
                $table->index('size_id');
            }
            if (Schema::hasColumn('purchase_return_items', 'product_variant_id')) {
                $table->index('product_variant_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_return_items', function (Blueprint $table) {
            $columns = ['color_id', 'product_variant_id'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('purchase_return_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
