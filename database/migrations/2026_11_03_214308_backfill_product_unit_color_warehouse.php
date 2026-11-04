<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {

            DB::table('products')->orderBy('id')->chunkById(100, function ($products) {

                foreach ($products as $product) {

                    // ألوان المنتج (كل المقاسات) وأرصدتها القديمة
                    $colors = DB::table('product_unit_colors as c')
                        ->join('product_units as u', 'u.id', '=', 'c.product_unit_id')
                        ->where('u.product_id', $product->id)
                        ->whereNull('c.deleted_at')
                        ->whereNull('u.deleted_at')
                        ->select('c.id', 'c.stock')
                        ->get();

                    $weights = [];
                    foreach ($colors as $c) {
                        $weights[$c->id] = max(0, (int) $c->stock);
                    }

                    $total = array_sum($weights);
                    if ($total <= 0) {
                        continue;
                    }

                    // مخازن المنتج اللي فيها رصيد
                    $warehouses = DB::table('product_warehouse')
                        ->where('product_id', $product->id)
                        ->where('stock', '>', 0)
                        ->get(['warehouse_id', 'stock']);

                    foreach ($warehouses as $wh) {

                        $S = (int) $wh->stock;
                        $alloc = [];
                        $remainders = [];
                        $given = 0;

                        // توزيع نسبي (الجزء الصحيح)
                        foreach ($weights as $colorId => $w) {
                            $alloc[$colorId] = intdiv($S * $w, $total);
                            $remainders[$colorId] = ($S * $w) % $total;
                            $given += $alloc[$colorId];
                        }

                        // توزيع الباقي على أكبر الكسور
                        $left = $S - $given;
                        arsort($remainders);
                        foreach (array_keys($remainders) as $colorId) {
                            if ($left <= 0) break;
                            $alloc[$colorId]++;
                            $left--;
                        }

                        $rows = [];
                        foreach ($alloc as $colorId => $qty) {
                            if ($qty <= 0) continue;
                            $rows[] = [
                                'warehouse_id'          => $wh->warehouse_id,
                                'product_unit_color_id' => $colorId,
                                'stock'                 => $qty,
                                'created_at'            => now(),
                                'updated_at'            => now(),
                            ];
                        }

                        // insertOrIgnore: ما يلمسش السجلات الموجودة أصلاً
                        if ($rows) {
                            DB::table('product_unit_color_warehouse')->insertOrIgnore($rows);
                        }
                    }
                }
            });
        });
    }

    public function down(): void
    {
        // مفيش رجوع آمن (ممكن يمسح بيانات اتسجلت بعد كده)
    }
};