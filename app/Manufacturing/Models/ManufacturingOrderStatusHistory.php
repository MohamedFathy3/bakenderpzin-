<?php
namespace App\Manufacturing\Models;
use App\Models\BaseModel; use App\Models\Product; use App\Models\Branch; use App\Models\Warehouse; 
class ManufacturingOrderStatusHistory extends BaseModel { public $timestamps=true; protected $guarded=['id']; public function order(){return $this->belongsTo(ManufacturingOrder::class,'manufacturing_order_id');} }
