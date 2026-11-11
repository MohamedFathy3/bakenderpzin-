<?php

namespace App\Models;

class InventoryCount extends BaseModel
{
    protected $guarded = ['id'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function logs()
    {
        return $this->hasMany(InventoryLog::class, 'inventory_count_id');
    }
}