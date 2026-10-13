<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesInvoiceResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,

            'customer' => [
                'id' => $this->customer?->id,
                'name' => $this->customer?->name,
            ],

            'sales_representative' => [
                'id' => $this->salesRepresentative?->id,
                'name' => $this->salesRepresentative?->name,
            ],

            'treasury' => $this->treasury?->name,
            'treasury_id' => $this->treasury_id,
            'branch' => $this->branch?->name,
            'warehouse' => $this->warehouse?->name,

            'currency' => $this->currency?->code,
            'tax' => $this->tax?->name,

            'payment_method' => $this->payment_method,
            'invoice_date' => $this->invoice_date,
            'due_date' => $this->due_date,
            'note' => $this->note,

            'total_amount' => number_format($this->total_amount, 2, '.', ''),
            'discount_percentage' => number_format($this->discount_percentage ?? 0, 2, '.', ''),
            'discount_amount' => number_format($this->discount_amount ?? 0, 2, '.', ''),
            'net_total' => number_format($this->net_total ?? 0, 2, '.', ''),
            'paid_amount' => (float) ($this->paid_amount ?? 0),
            'remaining_amount' => (float) ($this->remaining_amount ?? (($this->net_total ?? 0) - ($this->paid_amount ?? 0))),

            'items' => $this->items->map(function ($item) {
                $originalPrice = $item->price * $item->quantity;
                $discountAmount = $originalPrice - $item->total;
                $discountPercentage = ($originalPrice > 0) ? ($discountAmount / $originalPrice) * 100 : 0;
                
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name,
                    
                    'product_unit_id' => $item->product_unit_id,
                    'unit_name' => $item->unit?->name,
                    
                    'color_id' => $item->color_id,
                    'color_name' => $item->color?->name,
                    
                    'quantity' => $item->quantity,
                    'price' => number_format($item->price, 2, '.', ''),
                    'cost_price' => number_format($item->cost_price ?? $item->product?->cost ?? 0, 2, '.', ''),
                    'cost_is_estimated' => $item->cost_price === null,
                    'discount_percentage' => number_format(
                        $item->discount_percentage > 0 ? $item->discount_percentage : $discountPercentage, 
                        2, '.', ''
                    ),
                    'discount_amount' => number_format(
                        $item->discount_amount > 0 ? $item->discount_amount : $discountAmount, 
                        2, '.', ''
                    ),
                    'total' => number_format($item->total, 2, '.', ''),
                ];
            }),

            'created_at' => $this->created_at->format('Y-m-d H:i'),
        ];
    }
}