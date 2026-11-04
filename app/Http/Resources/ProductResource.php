<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'imageUrl' => $this->imageUrl,
            'image' => $this->image,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'beginning_balance' => $this->beginning_balance,

            // ✅ رصيد المنتج في المخزن المحدد (لو اتبعت warehouse_id)
            'stock' => (int) $this->stock,
            'total_stock_all_warehouses' => (int) ($this->total_stock_all_warehouses ?? $this->stock),

            'reorder_level' => $this->reorder_level,
            'price' => $this->price,
            'cost' => $this->cost,
            'active' => $this->active,
            'has_variants' => $this->has_variants,

            'units' => $this->units->map(function ($unit) {
                return [
                    'id' => $unit->id,
                    'unit_id' => $unit->unit_id,
                    'unit_name' => optional($unit->unit)->name,
                    'cost_price' => $unit->cost_price,
                    'sell_price' => $unit->sell_price,
                    'barcode' => $unit->barcode,

                    'colors' => $unit->colors->map(function ($color) {
                        // ✅ لو الـ relation محمّلة، خُد منها — وإلا 0
                        $hasWarehouseContext = $color->relationLoaded('warehouseStocks');
                        $loadedWarehouseStocks = $hasWarehouseContext
                            ? $color->warehouseStocks
                            : collect();

                        $first = $loadedWarehouseStocks->first();
                        $warehouseStock = $first ? (int) $first->stock : 0;

                        return [
                            'id' => $color->id,
                            'color_id' => $color->color_id,
                            'color' => optional($color->color)->name,
                            'stock' => $hasWarehouseContext ? $warehouseStock : (int) $color->stock,
                            'total_stock' => $hasWarehouseContext ? (int) $color->stock : null,
                            'hex_code' => optional($color->color)->hex_code,

                            // ✅ نفس شكل WarehouseProductResource
                            'warehouse_stock' => $warehouseStock,
                            'warehouseStocks' => $loadedWarehouseStocks
                                ->map(fn ($ws) => [
                                    'warehouse_id' => (int) $ws->warehouse_id,
                                    'stock' => (int) $ws->stock,
                                ])
                                ->values(),
                        ];
                    }),
                ];
            }),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'warehouses' => $this->whenLoaded('warehouses', function () {
                return $this->warehouses->map(fn ($w) => [
                    'warehouse_id' => $w->id,
                    'warehouse_name' => $w->name,
                    'stock' => (int) ($w->pivot->stock ?? 0),
                    'cost' => $w->pivot->cost ?? null,
                ]);
            }),
        ];
    }
}
