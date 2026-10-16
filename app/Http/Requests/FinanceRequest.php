<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'category' => ['required', 'string', Rule::in(['rent', 'utilities', 'salaries', 'supplies', 'purchases', 'marketing', 'maintenance', 'transport', 'insurance', 'taxes', 'other'])],
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:1000',
            'date' => 'required|date',
            'payment_method' => ['required', 'string', Rule::in(['cash', 'bank_transfer', 'check', 'credit_card', 'other'])],
            'reference_number' => 'nullable|string|max:255',
            
            // ✅ إضافة الحقول الجديدة
            'treasury_id' => 'nullable|exists:treasuries,id',
            'currency_id' => 'nullable|exists:currencies,id',
            'branch_id' => 'nullable|exists:branches,id',
        ];
    }

    public function messages()
    {
        return [
            'category.required' => 'الفئة مطلوبة',
            'category.in' => 'الفئة غير صالحة',
            'amount.required' => 'المبلغ مطلوب',
            'amount.numeric' => 'المبلغ يجب أن يكون رقماً',
            'amount.min' => 'المبلغ يجب أن يكون أكبر من صفر',
            'date.required' => 'التاريخ مطلوب',
            'date.date' => 'التاريخ غير صحيح',
            'payment_method.required' => 'طريقة الدفع مطلوبة',
            'payment_method.in' => 'طريقة الدفع غير صحيحة',
            'treasury_id.exists' => 'الخزينة غير موجودة',
            'currency_id.exists' => 'العملة غير موجودة',
            'branch_id.exists' => 'الفرع غير موجود',
        ];
    }
}