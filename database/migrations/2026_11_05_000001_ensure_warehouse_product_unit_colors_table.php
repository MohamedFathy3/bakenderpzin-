<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class EnsureWarehouseProductUnitColorsTable extends Migration
{
    public function up(): void
    {
        // الجدول الرسمي المستخدم في التحويلات هو هذا الاسم.
        // لا ننشئ جدولًا ثانيًا باسم مختلف حتى لا تتضارب الأرصدة.
        if (!Schema::hasTable('product_unit_color_warehouse')) {
            Schema::create('product_unit_color_warehouse', function (Blueprint $table) {
                $table->id();
                $table->foreignId('warehouse_id')
                    ->constrained('warehouses')
                    ->cascadeOnDelete();
                $table->foreignId('product_unit_color_id')
                    ->constrained('product_unit_colors')
                    ->cascadeOnDelete();
                $table->integer('stock')->default(0);
                $table->timestamps();
                $table->unique(
                    ['warehouse_id', 'product_unit_color_id'],
                    'unit_color_warehouse_unique'
                );
            });
        }
    }

    public function down(): void
    {
        // لا نحذف جدول المخزون الحقيقي عند rollback لهذه الـ migration الاحتياطية.
    }
}
