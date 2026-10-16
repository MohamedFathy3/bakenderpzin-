<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function paidExpense(): HasOne
    {
        return $this->hasOne(Finance::class);
    }

    public function syncPaidExpense(): void
    {
        $expense = Finance::withTrashed()->firstOrNew(['purchase_invoice_id' => $this->id]);

        if ((float) $this->paid_amount <= 0) {
            if ($expense->exists && !$expense->trashed()) {
                $expense->delete();
            }

            return;
        }

        if ($expense->trashed()) {
            $expense->restore();
        }

        $supplierName = $this->supplier()->value('name');

        $expense->fill([
            'category' => 'purchases',
            'amount' => $this->paid_amount,
            'description' => "سداد فاتورة مشتريات رقم {$this->invoice_number}" .
                ($supplierName ? " - المورد {$supplierName}" : ''),
            'date' => $this->invoice_date ?? now()->toDateString(),
            'payment_method' => 'cash',
            'reference_number' => $this->invoice_number,
            'treasury_id' => null,
            'currency_id' => $this->currency_id,
            'branch_id' => $this->branch_id,
        ])->save();
    }
}
