<?php
namespace App\Manufacturing\Models;
use App\Models\BaseModel; use App\Models\Product; use App\Models\Branch; use App\Models\Warehouse; 
class BomItem extends BaseModel { protected $guarded=['id']; protected $casts=['quantity'=>'decimal:6','scrap_percent'=>'decimal:4','is_optional'=>'boolean']; public function bomVersion(){return $this->belongsTo(BomVersion::class);} public function parent(){return $this->belongsTo(BomItem::class,'parent_item_id');} public function children(){return $this->hasMany(BomItem::class,'parent_item_id');} public function component(){return $this->belongsTo(Product::class,'component_product_id');} public function childBom(){return $this->belongsTo(BomVersion::class,'child_bom_version_id');} }
