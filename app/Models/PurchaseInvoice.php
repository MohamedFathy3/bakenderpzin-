<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseInvoice extends BaseModel
{
    protected $guarded = ['id'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function tax()
    {
        return $this->belongsTo(Tax::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    public function purchaseReturns()
    {
        return $this->hasMany(PurchaseReturn::class, 'purchase_invoices_id');
    }
    
    public function getRemainingAmountAttribute()
    {
        $returnedAmount = $this->getAttribute('purchase_returns_sum_total_amount');

        if ($returnedAmount === null) {
            $returnedAmount = $this->purchaseReturns()->sum('total_amount');
        }

        return max(0, (float) $this->total_amount - (float) $this->paid_amount - (float) $returnedAmount);
    }

       public function treasury()
    {
        return $this->belongsTo(Treasury::class);
    }

    // ✅ إضافة علاقة حركات الخزينة (polymorphic)
    public function treasuryTransactions()
    {
        return $this->morphMany(TreasuryTransaction::class, 'reference');
    }
}
