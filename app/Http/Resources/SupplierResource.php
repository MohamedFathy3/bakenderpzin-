<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray($request): array
    {
        $totalPurchases = $this->purchase_invoices_sum_total_amount ?? 0;
        $totalPaid = $this->purchase_invoices_sum_paid_amount ?? 0;
        $totalReturns = $this->purchase_returns_sum_total_amount
            ?? $this->purchaseReturns()->sum('total_amount');
        $remaining = max(0, $totalPurchases - $totalPaid - $totalReturns);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'address' => $this->address,
            'tax_number' => $this->tax_number,
            'note' => $this->note,
            'credit_limit' => $this->credit_limit,
            'payment_terms' => $this->payment_terms,
            'active' => $this->active,

            'financial_summary' => [
                'total_purchases' => (float) $totalPurchases,
                'total_paid' => (float) $totalPaid,
                'total_returns' => (float) $totalReturns,
                'remaining' => (float) $remaining,
            ],

            'created_at' => $this->created_at?->format('Y-m-d H:i'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i'),
        ];
    }
}
