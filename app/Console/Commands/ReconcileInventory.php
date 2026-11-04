<?php

namespace App\Console\Commands;

use App\Models\ProductUnitColorWarehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileInventory extends Command
{
    protected $signature = 'inventory:reconcile';
    protected $description = 'Repair warehouse variant balances and sync all stock aggregates';

    public function handle(): int
    {
        $productIds = DB::table('products')->pluck('id');
        $bar = $this->output->createProgressBar($productIds->count());
        $bar->start();

        foreach ($productIds as $productId) {
            DB::transaction(function () use ($productId) {
                $this->repairMissingWarehouseVariantStock((int) $productId);
                ProductUnitColorWarehouse::syncProduct((int) $productId);
            });
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("تم تصحيح {$productIds->count()} منتج");
        return self::SUCCESS;
    }

    private function repairMissingWarehouseVariantStock(int $productId): void
    {
        $variants = DB::table('product_unit_colors as puc')
            ->join('product_units as pu', 'pu.id', '=', 'puc.product_unit_id')
            ->where('pu.product_id', $productId)
            ->whereNull('puc.deleted_at')
            ->whereNull('pu.deleted_at')
            ->select('puc.id', 'puc.stock')
            ->orderBy('puc.id')
            ->get();

        if ($variants->isEmpty()) {
            return;
        }

        $warehouses = DB::table('product_warehouse')
            ->where('product_id', $productId)
            ->where('stock', '>', 0)
            ->get(['warehouse_id', 'stock']);

        foreach ($warehouses as $warehouse) {
            $rows = DB::table('product_unit_color_warehouse as pucw')
                ->join('product_unit_colors as puc', 'puc.id', '=', 'pucw.product_unit_color_id')
                ->join('product_units as pu', 'pu.id', '=', 'puc.product_unit_id')
                ->where('pucw.warehouse_id', $warehouse->warehouse_id)
                ->where('pu.product_id', $productId)
                ->select('pucw.id', 'pucw.stock')
                ->orderBy('pucw.id')
                ->get();

            $target = (int) $warehouse->stock;
            $current = (int) $rows->sum('stock');
            if ($current === $target) {
                continue;
            }

            if ($current === 0) {
                $weights = $variants->mapWithKeys(fn ($variant) => [$variant->id => max(0, (int) $variant->stock)]);
                $weightTotal = max(0, (int) $weights->sum());
                $remaining = $target;
                foreach ($variants->values() as $index => $variant) {
                    $quantity = $index === $variants->count() - 1
                        ? $remaining
                        : ($weightTotal > 0 ? (int) floor($target * $weights[$variant->id] / $weightTotal) : 0);
                    if ($quantity > 0) {
                        DB::table('product_unit_color_warehouse')->insert([
                            'warehouse_id' => $warehouse->warehouse_id,
                            'product_unit_color_id' => $variant->id,
                            'stock' => $quantity,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    $remaining -= $quantity;
                }
                continue;
            }

            $difference = $target - $current;
            if ($difference > 0) {
                DB::table('product_unit_color_warehouse')->where('id', $rows->last()->id)->increment('stock', $difference);
                continue;
            }

            $toRemove = -$difference;
            foreach ($rows->sortByDesc('stock') as $row) {
                if ($toRemove <= 0) break;
                $remove = min((int) $row->stock, $toRemove);
                DB::table('product_unit_color_warehouse')->where('id', $row->id)->decrement('stock', $remove);
                $toRemove -= $remove;
            }
        }
    }
}
