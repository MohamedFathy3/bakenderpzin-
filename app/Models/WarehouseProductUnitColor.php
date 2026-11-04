<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseProductUnitColor extends Model
{
    protected $table = 'warehouse_product_unit_colors';

    protected $fillable = [
        'warehouse_id',
        'product_unit_color_id',
        'stock',
    ];

    protected $casts = [
        'stock' => 'integer',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productUnitColor()
    {
        return $this->belongsTo(ProductUnitColor::class);
    }
}
