<?php
namespace App\Manufacturing\Models;
use App\Models\BaseModel; use App\Models\Product; use App\Models\Branch; use App\Models\Warehouse; 
class ManufacturingEquipment extends BaseModel { protected $guarded=['id']; protected $casts=['hourly_rate'=>'decimal:6']; public function branch(){return $this->belongsTo(Branch::class);} public function operations(){return $this->hasMany(ManufacturingOperation::class,'equipment_id');} }
