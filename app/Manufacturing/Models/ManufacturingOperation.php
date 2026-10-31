<?php
namespace App\Manufacturing\Models;
use App\Models\BaseModel; use App\Models\Product; use App\Models\Branch; use App\Models\Warehouse; 
class ManufacturingOperation extends BaseModel { protected $guarded=['id']; protected $casts=['planned_minutes'=>'decimal:6','actual_minutes'=>'decimal:6']; public function order(){return $this->belongsTo(ManufacturingOrder::class,'manufacturing_order_id');} public function equipment(){return $this->belongsTo(ManufacturingEquipment::class);} public function logs(){return $this->hasMany(ManufacturingOperationLog::class);} }
