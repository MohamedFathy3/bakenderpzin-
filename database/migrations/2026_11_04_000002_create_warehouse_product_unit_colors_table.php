<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The canonical table is created and backfilled by the 2026_11_03
        // migrations. This migration is kept as a compatibility no-op so an
        // older deployment cannot create a second, conflicting stock table.
        if (!Schema::hasTable('product_unit_color_warehouse')) {
            Schema::create('product_unit_color_warehouse', function (Blueprint $table) {
                $table->id();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->foreignId('product_unit_color_id')->constrained('product_unit_colors')->cascadeOnDelete();
                $table->integer('stock')->default(0);
                $table->timestamps();
                $table->unique(['warehouse_id', 'product_unit_color_id'], 'unit_color_warehouse_unique');
            });
        }
    }

    public function down(): void
    {
        // Do not drop the canonical production stock table from this
        // compatibility migration.
    }
};
