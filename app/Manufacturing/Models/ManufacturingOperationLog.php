<?php
namespace App\Manufacturing\Models;
use App\Models\BaseModel; use App\Models\Product; use App\Models\Branch; use App\Models\Warehouse; 
class ManufacturingOperationLog extends BaseModel { protected $guarded=['id']; protected $casts=['minutes'=>'decimal:6','labor_cost'=>'decimal:6','machine_cost'=>'decimal:6']; public function operation(){return $this->belongsTo(ManufacturingOperation::class,'manufacturing_operation_id');} }
