<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('warehouse_product_unit_colors')) {
            Schema::create('warehouse_product_unit_colors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->foreignId('product_unit_color_id')->constrained('product_unit_colors')->cascadeOnDelete();
                $table->integer('stock')->default(0);
                $table->timestamps();
                $table->unique(['warehouse_id', 'product_unit_color_id'], 'warehouse_variant_unique');
            });
        }

        // Backfill only when the table is empty. This also repairs deployments
        // where the migration was marked as run but the table was not created.
        if ((int) DB::table('warehouse_product_unit_colors')->count() === 0) {
            foreach (DB::table('product_warehouse')->orderBy('id')->get() as $warehouseStock) {
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
                    $allocated = $index === $lastIndex
                        ? $remaining
                        : ($weightTotal > 0
                            ? min($remaining, (int) floor($total * max(0, (int) $variant->old_stock) / $weightTotal))
                            : 0);

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
        }

        DB::statement('UPDATE product_unit_colors puc SET stock = (SELECT COALESCE(SUM(wpuc.stock), 0) FROM warehouse_product_unit_colors wpuc WHERE wpuc.product_unit_color_id = puc.id)');
        DB::statement('UPDATE products p SET stock = (SELECT COALESCE(SUM(pw.stock), 0) FROM product_warehouse pw WHERE pw.product_id = p.id)');
    }

    public function down(): void
    {
        // The original migration owns the table. Keep this repair migration
        // non-destructive so rolling it back cannot delete production stock.
    }
};
