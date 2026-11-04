<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) نسخة احتياطية من الرقم القديم (مرة واحدة)
        if (!Schema::hasColumn('product_unit_colors', 'legacy_stock')) {
            Schema::table('product_unit_colors', function (Blueprint $t) {
                $t->integer('legacy_stock')->nullable()->after('stock');
            });
            DB::statement('UPDATE product_unit_colors SET legacy_stock = stock');
        }

        DB::transaction(function () {
            DB::table('products')->orderBy('id')->chunkById(100, function ($products) {
                foreach ($products as $product) {

                    $colors = DB::table('product_unit_colors as c')
                        ->join('product_units as u', 'u.id', '=', 'c.product_unit_id')
                        ->where('u.product_id', $product->id)
                        ->whereNull('c.deleted_at')
                        ->whereNull('u.deleted_at')
                        ->select('c.id', 'c.legacy_stock')
                        ->get();

                    if ($colors->isEmpty()) continue; // منتج بدون ألوان

                    // الأوزان = الرصيد القديم؛ لو كله صفر نوزع بالتساوي
                    $weights = [];
                    foreach ($colors as $c) $weights[$c->id] = max(0, (int) $c->legacy_stock);
                    if (array_sum($weights) <= 0) {
                        foreach ($weights as $id => $_) $weights[$id] = 1;
                    }
                    $total = array_sum($weights);

                    $warehouses = DB::table('product_warehouse')
                        ->where('product_id', $product->id)
                        ->get(['warehouse_id', 'stock']);

                    foreach ($warehouses as $wh) {
                        $S = max(0, (int) $wh->stock);

                        // احذف أرقام الألوان القديمة في المخزن ده وأعد التوزيع على رصيد المنتج
                        DB::table('product_unit_color_warehouse')
                            ->where('warehouse_id', $wh->warehouse_id)
                            ->whereIn('product_unit_color_id', array_keys($weights))
                            ->delete();

                        if ($S === 0) continue;

                        $alloc = []; $rem = []; $given = 0;
                        foreach ($weights as $id => $w) {
                            $alloc[$id] = intdiv($S * $w, $total);
                            $rem[$id]   = ($S * $w) % $total;
                            $given     += $alloc[$id];
                        }
                        $left = $S - $given;
                        arsort($rem);
                        foreach (array_keys($rem) as $id) {
                            if ($left <= 0) break;
                            $alloc[$id]++; $left--;
                        }

                        $rows = [];
                        foreach ($alloc as $id => $qty) {
                            if ($qty <= 0) continue;
                            $rows[] = [
                                'warehouse_id' => $wh->warehouse_id,
                                'product_unit_color_id' => $id,
                                'stock' => $qty,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                        if ($rows) DB::table('product_unit_color_warehouse')->insert($rows);
                    }

                    // العمود القديم يتبع مجموع المخازن (النسخة الأصلية محفوظة في legacy_stock)
                    DB::statement("
                        UPDATE product_unit_colors puc
                        SET puc.stock = COALESCE((
                            SELECT SUM(w.stock) FROM product_unit_color_warehouse w
                            WHERE w.product_unit_color_id = puc.id
                        ), 0)
                        WHERE puc.id IN (" . $colors->pluck('id')->implode(',') . ")
                    ");
                }
            });
        });
    }

    public function down(): void
    {
        // رجوع آمن: رجّع الرقم القديم من النسخة الاحتياطية
        if (Schema::hasColumn('product_unit_colors', 'legacy_stock')) {
            DB::statement('UPDATE product_unit_colors SET stock = legacy_stock');
        }
    }
};