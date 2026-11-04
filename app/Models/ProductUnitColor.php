<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductUnitColor extends BaseModel
{
    protected $guarded = ['id'];

    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function color()
    {
        return $this->belongsTo(Color::class);
    }

    public function warehouseStocks()
    {
        return $this->hasMany(WarehouseProductUnitColor::class, 'product_unit_color_id');
    }
}
