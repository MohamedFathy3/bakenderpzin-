<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;   // ✅ كان ناقص

class ProductUnitColorWarehouse extends Model
{
    protected $table = 'product_unit_color_warehouse';

    protected $fillable = [
        'warehouse_id',
        'product_unit_color_id',
        'stock',
    ];

    protected $casts = [
        'stock' => 'integer',
    ];

    /**
     * تحديث كل الـ aggregates المرتبطة بلون معين
     * (بيستدعي syncProduct اللي بيعمل الشغل كله مرة واحدة)
     */
    public static function syncAggregates(int $colorRecordId): void
    {
        $color = DB::table('product_unit_colors')->find($colorRecordId);
        if (!$color) return;

        $unit = DB::table('product_units')->find($color->product_unit_id);
        if (!$unit) return;

        self::syncProduct((int) $unit->product_id);
    }

    /**
     * المزامنة الكاملة لمنتج واحد — المصدر الوحيد هو product_unit_color_warehouse
     *
     * الخطوات:
     *  1) product_unit_colors.stock = SUM(product_unit_color_warehouse.stock)
     *  2) product_warehouse.stock   = SUM(product_unit_color_warehouse.stock) لكل مخزن (لو المنتج له ألوان)
     *  3) products.stock            = SUM(product_warehouse.stock)  ← دايماً
     */
    public static function syncProduct(int $productId): void
    {
        // ========== 1) تحديث إجمالي كل لون ==========
        $colors = DB::table('product_unit_colors as puc')
            ->join('product_units as pu', 'pu.id', '=', 'puc.product_unit_id')
            ->where('pu.product_id', $productId)
            ->whereNull('puc.deleted_at')
            ->select('puc.id')
            ->get();

        foreach ($colors as $c) {
            $total = (int) DB::table('product_unit_color_warehouse')
                ->where('product_unit_color_id', $c->id)
                ->sum('stock');

            DB::table('product_unit_colors')
                ->where('id', $c->id)
                ->update(['stock' => $total]);
        }

        // ========== 2) product_warehouse (فقط لو المنتج له ألوان) ==========
        if ($colors->isNotEmpty()) {
            self::syncProductWarehouse($productId);
        }

        // ========== 3) products.stock = SUM(product_warehouse.stock) ==========
        $productTotal = (int) DB::table('product_warehouse')
            ->where('product_id', $productId)
            ->sum('stock');

        DB::table('products')
            ->where('id', $productId)
            ->update(['stock' => $productTotal]);
    }

    /**
     * إعادة حساب product_warehouse لكل مخزن من product_unit_color_warehouse
     */
    private static function syncProductWarehouse(int $productId): void
    {
        $perWarehouse = DB::table('product_unit_color_warehouse as pucw')
            ->join('product_unit_colors as puc', 'puc.id', '=', 'pucw.product_unit_color_id')
            ->join('product_units as pu', 'pu.id', '=', 'puc.product_unit_id')
            ->where('pu.product_id', $productId)
            ->whereNull('puc.deleted_at')
            ->groupBy('pucw.warehouse_id')
            ->selectRaw('pucw.warehouse_id, SUM(pucw.stock) as total')
            ->get();

        $activeWarehouseIds = $perWarehouse->pluck('warehouse_id')->toArray();

        foreach ($perWarehouse as $row) {
            DB::table('product_warehouse')->updateOrInsert(
                [
                    'warehouse_id' => $row->warehouse_id,
                    'product_id'   => $productId,
                ],
                [
                    'stock'      => (int) $row->total,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        // احذف سجلات المخازن اللي بقى رصيدها 0 (مش موجودة في product_unit_color_warehouse)
        if (!empty($activeWarehouseIds)) {
            DB::table('product_warehouse')
                ->where('product_id', $productId)
                ->whereNotIn('warehouse_id', $activeWarehouseIds)
                ->delete();
        } else {
            DB::table('product_warehouse')
                ->where('product_id', $productId)
                ->delete();
        }
    }
}