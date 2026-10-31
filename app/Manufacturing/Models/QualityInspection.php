<?php
namespace App\Manufacturing\Models;
use App\Models\BaseModel; use App\Models\Product; use App\Models\Branch; use App\Models\Warehouse; 
class QualityInspection extends BaseModel { protected $guarded=['id']; protected $casts=['quantity'=>'decimal:6']; public function order(){return $this->belongsTo(ManufacturingOrder::class,'manufacturing_order_id');} public function operation(){return $this->belongsTo(ManufacturingOperation::class,'manufacturing_operation_id');} }
