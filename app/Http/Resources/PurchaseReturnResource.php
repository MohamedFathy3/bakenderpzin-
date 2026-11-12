<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'return_number' => $this->return_number,

            'purchase_invoices_id' => $this->purchase_invoices_id,
            'invoice_number' => optional($this->purchaseInvoice)->invoice_number,
            'supplier_name' => optional(optional($this->purchaseInvoice)->supplier)->name,

            'total_amount' => (float) $this->total_amount,
            'paid_amount' => (float) $this->paid_amount,
            'reason' => $this->reason,
            'return_date' => $this->return_date,
            'payment_method' => $this->payment_method,

            'treasury_id' => $this->treasury_id,
            'treasury_name' => $this->treasury?->name,

            'currency_id' => $this->currency_id,
            'currency_code' => $this->currency?->code,

            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse?->name,

            'items' => $this->items->map(fn($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                
                'product_unit_id' => $item->product_unit_id,
                'unit_name' => $item->unit?->name,
                
                'color_id' => $item->color_id,
                'color_name' => $item->color?->name,
                
                // 'product_variant_id' => $item->product_variant_id,
                'variant_name' => $item->variant?->name,

                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
            ]),

            'created_at' => $this->created_at?->format('Y-m-d H:i'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i'),
        ];
    }
}
