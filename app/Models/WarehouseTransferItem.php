<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseTransferItem extends Model
{
    protected $table = 'warehouse_transfer_items';

    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'integer',
        'source_before' => 'integer',
        'source_after' => 'integer',
        'destination_before' => 'integer',
        'destination_after' => 'integer',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(
            WarehouseTransfer::class,
            'warehouse_transfer_id'
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            Product::class,
            'product_id'
        );
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(
            ProductUnit::class,
            'product_unit_id'
        );
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(
            Color::class,
            'color_id'
        );
    }
}
