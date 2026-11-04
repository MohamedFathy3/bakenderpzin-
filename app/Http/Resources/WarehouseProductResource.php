<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->id,
            'name' => $this->name,
            'product_name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,

            // ✅ رصيد المنتج الإجمالي داخل المخزن ده
            'stock' => (int) ($this->pivot->stock ?? 0),

            'units' => $this->units->map(function ($unit) {
                return [
                    'id' => $unit->id,
                    'unit_id' => $unit->unit_id,
                    'unit_name' => optional($unit->unit)->name,
                    'barcode' => $unit->barcode,
                    'cost_price' => $unit->cost_price,
                    'sell_price' => $unit->sell_price,

                    'colors' => $unit->colors->map(function ($color) {
                        return [
                            'id' => $color->id,
                            'color_id' => $color->color_id,
                            'color' => optional($color->color)->name,
                            'hex_code' => optional($color->color)->hex_code,

                            // إجمالي كل المخازن (للعرض فقط)
                            'stock' => (int) $color->stock,

                            // ✅ رصيد المخزن المحدد — ده اللي الفرونت هيستخدمه
                            'warehouse_stock' => (int) ($color->warehouse_stock ?? 0),

                            // ✅ كمان نرجّع الـ warehouseStocks كاملة (احتياطي)
                            'warehouseStocks' => $color->warehouseStocks
                                ->map(fn ($ws) => [
                                    'warehouse_id' => $ws->warehouse_id,
                                    'stock' => (int) $ws->stock,
                                ])
                                ->values(),
                        ];
                    }),
                ];
            }),
        ];
    }
}