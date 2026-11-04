<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductUnitColorResource extends JsonResource
{
    public function toArray(Request $request): array

    {
        return [
            'id'        => $this->id,
            'color_id'  => $this->color_id,
            'color'     => optional($this->color)->name,
            'stock'     => $this->stock,
            'warehouseStocks' => $this->whenLoaded('warehouseStocks', fn () => $this->warehouseStocks->map(fn ($warehouseStock) => [
                'warehouse_id' => $warehouseStock->warehouse_id,
                'stock' => (int) $warehouseStock->stock,
            ])->values()),
            'hex_code'  => optional($this->color)->hex_code,

    return [
        'id' => $this->id,
        'color_id' => $this->color_id,
        'color' => optional($this->color)->name,
        'stock' => $hasWarehouseContext
            ? (int) ($warehouseStock?->stock ?? 0)
            : (int) $this->stock,          // = مجموع المخازن بعد الـ migration
        'total_stock' => $hasWarehouseContext ? (int) $this->stock : null,
        'hex_code' => optional($this->color)->hex_code,
    ];
}   
}