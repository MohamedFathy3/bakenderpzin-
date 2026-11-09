<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\WarehouseTransferItem;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseTransfer extends Model
{
    protected $table = 'warehouse_transfers';

    protected $guarded = ['id'];

    protected $casts = [
        'inventory_value' => 'decimal:2',
        'transfer_date' => 'datetime',
    ];

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(
            Warehouse::class,
            'from_warehouse_id'
        );
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(
            Warehouse::class,
            'to_warehouse_id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            WarehouseTransferItem::class,
            'warehouse_transfer_id'
        );
    }
}
