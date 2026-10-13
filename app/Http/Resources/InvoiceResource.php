<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray($request)
    {
        $payments = $this->payments->map(fn ($payment) => [
            'method' => $payment->method,
            'amount' => $payment->amount,
        ])->values();

        if ((float) ($this->loyalty_discount_amount ?? 0) > 0) {
            $payments->push([
                'method' => 'loyalty_points',
                'amount' => (float) $this->loyalty_discount_amount,
            ]);
        }

        return [
            'id'               => $this->id,
            'invoice_number'   => $this->invoice_number,
            'status'           => $this->status,

            'customer' => [
                'id'   => $this->customer?->id,
                'name' => $this->customer?->name,
            ],

            'salesRepresentative' => [
                'id'   => $this->salesRepresentative?->id,
                'name' => $this->salesRepresentative?->name,
            ],

            // ✅ إضافة الكاشير (الموظف اللي سجل الفاتورة)
            'cashier' => $this->cashier ? [
                'id'          => $this->cashier->id,
                'name'        => $this->cashier->name,
                'name_ar'     => $this->cashier->name_ar,
                'employee_code' => $this->cashier->employee_code,
            ] : null,

            // ✅ إضافة الخزينة
            'treasury' => $this->treasury ? [
                'id'          => $this->treasury->id,
                'name'        => $this->treasury->name,
                'name_ar'     => $this->treasury->name_ar,
                'is_main'     => $this->treasury->is_main,
            ] : null,

            'amounts' => [
                'total'     => (float) $this->total_amount,
                'paid'      => (float) $this->paid_amount,
                'remaining' => (float) $this->remaining_amount,
            ],

            'total_amount' => (float) $this->total_amount,
            'discount_percentage' => (float) $this->discount_percentage,
            'discount_amount' => (float) $this->discount_amount,
            'loyalty_points_redeemed' => (int) ($this->loyalty_points_redeemed ?? 0),
            'loyalty_discount_amount' => (float) ($this->loyalty_discount_amount ?? 0),



            'items' => InvoiceItemResource::collection($this->items),

            'payments' => $payments,

            'created_at' => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
