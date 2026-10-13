<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesInvoiceReturnResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'return_number' => $this->return_number,

            // ✅ بيانات الفاتورة الأصلية
            'sales_invoice_id' => $this->sales_invoice_id,
            'invoice_number' => optional($this->invoice)->invoice_number,
            'currency_code' => optional(optional($this->invoice)->currency)->code,
            'currency_name' => optional(optional($this->invoice)->currency)->name,
            'customer' => optional(optional($this->invoice)->customer)->name,

            // ✅ بيانات العميل (منفصلة)
            'customer_id' => optional(optional($this->invoice)->customer)->id,
            'customer_name' => optional(optional($this->invoice)->customer)->name,
            'customer_points' => optional(optional($this->invoice)->customer)->point ?? 0,
            'customer_level' => optional(optional($this->invoice)->customer)->level ?? 'bronze',

            // ✅ بيانات الخزينة
            'treasury_id' => $this->treasury_id,
            'treasury_name' => optional($this->treasury)->name,
            'treasury_balance' => optional($this->treasury)->balance ? number_format($this->treasury->balance, 2, '.', '') : null,

            // ✅ بيانات الكاشير والوردية
            'cashier_id' => $this->cashier_id,
            'cashier_name' => optional($this->cashier)->name,
            'shift_id' => $this->shift_id,
            'shift_number' => optional($this->shift)->id,

            // ✅ بيانات المرتجع
            'return_method' => $this->return_method,
            'return_method_label' => $this->getReturnMethodLabel($this->return_method),
            'total_amount' => number_format($this->total_amount, 2, '.', ''),
            'note' => $this->note,
            'is_direct' => $this->is_direct,

            // ============================================================
            // ✅ ✅ ✅ تفاصيل المنتجات (كاملة زي SalesInvoiceResource)
            // ============================================================
            'items' => $this->items->map(function ($item) {
                $originalPrice = $item->price * $item->quantity;
                $discountAmount = $item->discount ?? 0;
                $discountPercentage = ($originalPrice > 0) ? ($discountAmount / $originalPrice) * 100 : 0;

                return [
                    'sales_invoice_item_id' => $item->sales_invoice_item_id,
                    // ✅ بيانات المنتج الأساسية
                    'product_id' => $item->product_id,
                    'product_name' => optional($item->product)->name,
                    'product_code' => optional($item->product)->code,
                    'product_sku' => optional($item->product)->sku,

                    // ✅ بيانات الوحدة
                    'product_unit_id' => $item->product_unit_id,
                    'unit_name' => optional($item->unit)->name,

                    // ✅ بيانات اللون
                    'color_id' => $item->color_id,
                    'color_name' => optional($item->color)->name,
                    'color_code' => optional($item->color)->code,

                    // ✅ بيانات الكمية والسعر
                    'quantity' => $item->quantity,
                    'price' => number_format($item->price, 2, '.', ''),
                    'original_total' => number_format($originalPrice, 2, '.', ''),

                    // ✅ بيانات الخصم
                    'discount_percentage' => number_format($item->discount_percentage ?? $discountPercentage, 2, '.', ''),
                    'discount_amount' => number_format($item->discount_amount ?? $discountAmount, 2, '.', ''),

                    // ✅ بيانات الضريبة
                    'tax_percentage' => number_format($item->tax ?? 0, 2, '.', ''),
                    'tax_amount' => number_format(
                        isset($item->tax) ? (($originalPrice - $discountAmount) * $item->tax) / 100 : 0, 
                        2, '.', ''
                    ),

                    // ✅ الإجمالي النهائي للعنصر
                    'total' => number_format($item->total ?? ($originalPrice - $discountAmount), 2, '.', ''),

                    // ✅ سبب الإرجاع
                    'reason' => $item->reason,
                    'reason_label' => $this->getReasonLabel($item->reason),
                ];
            }),

            // ✅ الإحصائيات
            'statistics' => [
                'items_count' => $this->items->count(),
                'total_quantity' => $this->items->sum('quantity'),
                'total_amount' => number_format($this->total_amount, 2, '.', ''),
            ],

            // ✅ التواريخ
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    // ============================================================
    // ✅ دالة مساعدة لترجمة سبب الإرجاع
    // ============================================================
    private function getReasonLabel($reason)
    {
        $labels = [
            'defective' => 'منتج تالف',
            'wrong_item' => 'منتج خاطئ',
            'damaged' => 'منتج متضرر',
            'customer_change' => 'تغيير رأي العميل',
            'other' => 'أسباب أخرى',
        ];

        return $labels[$reason] ?? $reason;
    }

    // ============================================================
    // ✅ دالة مساعدة لترجمة طريقة الدفع
    // ============================================================
    private function getReturnMethodLabel($method)
    {
        $labels = [
            'cash' => 'نقدي',
            'card' => 'بطاقة',
            'wallet' => 'محفظة',
            'bank' => 'تحويل بنكي',
        ];

        return $labels[$method] ?? $method;
    }
}