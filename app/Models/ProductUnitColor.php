<?php

namespace App\Models;

class ProductUnitColor extends BaseModel
{
    protected $guarded = ['id'];

    protected $casts = [
        'stock' => 'integer',
    ];

    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function color()
    {
        return $this->belongsTo(Color::class);
    }

<<<<<<< HEAD
public function warehouseStocks()
{
    return $this->hasMany(
        ProductUnitColorWarehouse::class,
        'product_unit_color_id',
        'id'
    );
=======
    public function warehouseStocks()
    {
        return $this->hasMany(WarehouseProductUnitColor::class, 'product_unit_color_id');
    }
>>>>>>> 07f3c401259cc956a31dfc435ceedc928485248d
}
}