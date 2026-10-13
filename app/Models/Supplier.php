<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends BaseModel
{
    protected $guarded = ['id'];
    
    public function purchaseInvoices()
    {
        return $this->hasMany(PurchaseInvoice::class);
    }

    public function purchaseReturns()
    {
        return $this->hasManyThrough(
            PurchaseReturn::class,
            PurchaseInvoice::class,
            'supplier_id',
            'purchase_invoices_id',
            'id',
            'id'
        );
    }
}
