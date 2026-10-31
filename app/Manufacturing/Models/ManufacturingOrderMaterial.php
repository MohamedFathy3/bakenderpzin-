<?php
namespace App\Manufacturing\Models;
use App\Models\BaseModel; use App\Models\Product; use App\Models\Branch; use App\Models\Warehouse; 
class ManufacturingOrderMaterial extends BaseModel { protected $guarded=['id']; protected $casts=['required_quantity'=>'decimal:6','reserved_quantity'=>'decimal:6','issued_quantity'=>'decimal:6','returned_quantity'=>'decimal:6','scrapped_quantity'=>'decimal:6']; public function order(){return $this->belongsTo(ManufacturingOrder::class,'manufacturing_order_id');} public function product(){return $this->belongsTo(Product::class);} public function bomItem(){return $this->belongsTo(BomItem::class);} }
