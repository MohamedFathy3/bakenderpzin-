<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductUnitColorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hasWarehouseContext = $this->relationLoaded('warehouseStocks');
        $warehouseStock = $hasWarehouseContext ? $this->warehouseStocks->first() : null;

        return [
            'id' => $this->id,
            'color_id' => $this->color_id,
            'color' => optional($this->color)->name,
            'stock' => $hasWarehouseContext
                ? (int) ($warehouseStock?->stock ?? 0)
                : (int) $this->stock,
            'total_stock' => $hasWarehouseContext ? (int) $this->stock : null,
            'warehouseStocks' => $this->whenLoaded('warehouseStocks', fn () => $this->warehouseStocks->map(fn ($row) => [
                'warehouse_id' => (int) $row->warehouse_id,
                'stock' => (int) $row->stock,
            ])->values()),
            'hex_code' => optional($this->color)->hex_code,
        ];
    }
}
