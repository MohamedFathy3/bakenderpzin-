<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FinanceResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'purchase_invoice_id' => $this->purchase_invoice_id,
            'category' => $this->category,
            'amount' => $this->amount,
            'formatted_amount' => $this->formatted_amount,
            'description' => $this->description,
            'date' => $this->date->format('Y-m-d'),
            'date_formatted' => $this->date->format('d/m/Y'),
            'payment_method' => $this->payment_method,
            'payment_method_arabic' => $this->payment_method_arabic,
            'reference_number' => $this->reference_number,
            
            // ✅ الخزينة
            'treasury_id' => $this->treasury_id,
            'treasury' => $this->treasury ? [
                'id' => $this->treasury->id,
                'name' => $this->treasury->name,
                'name_ar' => $this->treasury->name_ar,
                'balance' => $this->treasury->balance,
            ] : null,
            
            // ✅ العملة
            'currency_id' => $this->currency_id,
            'currency' => $this->currency ? [
                'id' => $this->currency->id,
                'code' => $this->currency->code,
                'name' => $this->currency->name,
                'name_ar' => $this->currency->name_ar,
                'symbol' => $this->currency->symbol,
            ] : null,
            
            // ✅ الفرع
            'branch_id' => $this->branch_id,
            'branch' => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'name_ar' => $this->branch->name_ar,
            ] : null,
            
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}