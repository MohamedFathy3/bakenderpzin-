<?php
namespace App\Manufacturing\Models;
use App\Models\BaseModel; use App\Models\Product; use App\Models\Branch; use App\Models\Warehouse; 
class BomVersion extends BaseModel { protected $guarded=['id']; protected $casts=['yield_quantity'=>'decimal:6']; public function product(){return $this->belongsTo(Product::class);} public function items(){return $this->hasMany(BomItem::class);} public function branch(){return $this->belongsTo(Branch::class);} }
