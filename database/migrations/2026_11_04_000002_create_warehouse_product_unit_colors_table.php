<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('warehouse_product_unit_colors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_unit_color_id')->constrained('product_unit_colors')->cascadeOnDelete();
            $table->integer('stock')->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_unit_color_id'], 'warehouse_variant_unique');
        });

        // The warehouse aggregate is the source of truth. Distribute each warehouse
        // aggregate across its product variants proportionally to the old global
        // variant balances, then make global variant balances equal their warehouse sums.
        $warehouses = DB::table('product_warehouse')->orderBy('id')->get();
        foreach ($warehouses as $warehouseStock) {
            $variants = DB::table('product_unit_colors as puc')
                ->join('product_units as pu', 'pu.id', '=', 'puc.product_unit_id')
                ->where('pu.product_id', $warehouseStock->product_id)
                ->whereNull('puc.deleted_at')
                ->whereNull('pu.deleted_at')
                ->select('puc.id', 'puc.stock as old_stock')
                ->orderBy('puc.id')
                ->get();

            if ($variants->isEmpty()) {
                continue;
            }

            $total = max(0, (int) $warehouseStock->stock);
            $weightTotal = max(0, (int) $variants->sum(fn ($variant) => max(0, (int) $variant->old_stock)));
            $remaining = $total;
            $lastIndex = $variants->count() - 1;

            foreach ($variants->values() as $index => $variant) {
                if ($index === $lastIndex) {
                    $allocated = $remaining;
                } elseif ($weightTotal > 0) {
                    $allocated = (int) floor($total * max(0, (int) $variant->old_stock) / $weightTotal);
                    $allocated = min($allocated, $remaining);
                } else {
                    $allocated = 0;
                }

                DB::table('warehouse_product_unit_colors')->insert([
                    'warehouse_id' => $warehouseStock->warehouse_id,
                    'product_unit_color_id' => $variant->id,
                    'stock' => $allocated,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $remaining -= $allocated;
            }
        }

        DB::statement('UPDATE product_unit_colors puc SET stock = (SELECT COALESCE(SUM(wpuc.stock), 0) FROM warehouse_product_unit_colors wpuc WHERE wpuc.product_unit_color_id = puc.id)');
        DB::statement('UPDATE products p SET stock = (SELECT COALESCE(SUM(pw.stock), 0) FROM product_warehouse pw WHERE pw.product_id = p.id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_product_unit_colors');
    }
};
