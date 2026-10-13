<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseInvoiceRequest;
use App\Http\Resources\PurchaseInvoiceResource;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Tax;
use App\Models\Transfer;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseInvoiceController extends Controller
{

    private function parseTaxRate($rate): float
    {
        $rate = rtrim(trim((string) $rate), '%');

        if ($rate === '' || !is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
            throw new \InvalidArgumentException('معدل الضريبة المحدد غير صالح');
        }

        return (float) $rate;
    }

    private function calculateInvoiceTotals(array $items, float $defaultTaxRate): array
    {
        $subtotal = 0;
        $discountTotal = 0;
        $taxTotal = 0;
        $calculatedItems = [];

        foreach ($items as $index => $item) {
            $lineSubtotal = round($item['quantity'] * $item['price'], 2);
            $lineDiscount = round($lineSubtotal * (($item['discount'] ?? 0) / 100), 2);
            $taxRate = $this->parseTaxRate($item['tax'] ?? $defaultTaxRate);
            $lineTax = round(($lineSubtotal - $lineDiscount) * ($taxRate / 100), 2);
            $lineTotal = $lineSubtotal - $lineDiscount + $lineTax;

            $subtotal += $lineSubtotal;
            $discountTotal += $lineDiscount;
            $taxTotal += $lineTax;
            $calculatedItems[$index] = [
                'tax_rate' => $taxRate,
                'total' => $lineTotal,
            ];
        }

        $subtotal = round($subtotal, 2);
        $discountTotal = round($discountTotal, 2);
        $taxTotal = round($taxTotal, 2);

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'total' => round($subtotal - $discountTotal + $taxTotal, 2),
            'items' => $calculatedItems,
        ];
    }

    public function store(PurchaseInvoiceRequest $request)
    {
        DB::beginTransaction();
    
        try {
    
            // Generate invoice number
            $invoiceNumber = 'PI-' . now()->format('Ymd') . '-' . rand(1000, 9999);
    
            /*
            =============================
            حساب المجاميع
            =============================
            */
    
            $defaultTaxRate = $request->tax_id
                ? $this->parseTaxRate(Tax::findOrFail($request->tax_id)->rate)
                : 0;
            $totals = $this->calculateInvoiceTotals($request->items, $defaultTaxRate);
            $subtotal = $totals['subtotal'];
            $discountTotal = $totals['discount_total'];
            $taxTotal = $totals['tax_total'];
            $total = $totals['total'];
            $remaining = $total - ($request->paid_amount ?? 0);

            if (($request->paid_amount ?? 0) > $total) {
                throw new \Exception('المبلغ المدفوع أكبر من إجمالي الفاتورة');
            }
    
            /*
            =============================
            إنشاء الفاتورة
            =============================
            */
    
            $invoice = PurchaseInvoice::create([
                'invoice_number' => $invoiceNumber,
                'supplier_id' => $request->supplier_id,
                'branch_id' => $request->branch_id,
                'warehouse_id' => $request->warehouse_id,
                'currency_id' => $request->currency_id,
                'tax_id' => $request->tax_id,
                'treasury_id' => $request->treasury_id,
                'invoice_date' => $request->invoice_date,
                'due_date' => $request->due_date,
                'payment_method' => $request->payment_method,
                'note' => $request->note,
                'paid_amount' => $request->paid_amount ?? 0,
                'remaining_amount' => $remaining,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,  // ✅ القيمة مش النسبة
                'tax_total' => $taxTotal,
                'total_amount' => $total,
            ]);
    
            foreach ($request->items as $index => $item) {
    
                PurchaseInvoiceItem::create([
                    'purchase_invoice_id' => $invoice->id,
                    'product_id' => $item['product_id'],
                    // ❌ شيل product_variant_id عشان الجدول مش موجود
                    // 'product_variant_id' => $item['product_variant_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'product_unit_id' => $item['unit_id'] ?? null,
                    'color_id' => $item['color_id'] ?? null,
                    'price' => $item['price'],
                    'discount' => $item['discount'] ?? 0,  // ✅ النسبة المئوية
                    'tax' => $totals['items'][$index]['tax_rate'],
                    'total' => $totals['items'][$index]['total'],
                ]);
    
                /*
                =============================
                تحديث product_unit_colors
                =============================
                */
    
                $productUnit = DB::table('product_units')
                    ->where('product_id', $item['product_id'])
                    ->where('unit_id', $item['unit_id'])
                    ->first();
    
                if (!$productUnit) {
                    throw new \Exception('Product unit not found');
                }
    
                if (!empty($item['color_id'])) {
                    $colorStock = DB::table('product_unit_colors')
                        ->where('product_unit_id', $productUnit->id)
                        ->where('color_id', $item['color_id'])
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->orderBy('id')
                        ->first();

                    if ($colorStock) {
                        DB::table('product_unit_colors')->where('id', $colorStock->id)
                            ->increment('stock', $item['quantity']);
                    } else {
                        DB::table('product_unit_colors')->insert([
                            'product_unit_id' => $productUnit->id,
                            'color_id' => $item['color_id'],
                            'stock' => $item['quantity'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
    
                /*
                =============================
                تحديث stock العام
                =============================
                */
    
                $product = Product::find($item['product_id']);
    
                if ($product) {
                    if (!empty($item['color_id'])) {
                        $variantTotal = DB::table('product_unit_colors as puc')
                            ->join('product_units as pu', 'pu.id', '=', 'puc.product_unit_id')
                            ->where('pu.product_id', $product->id)
                            ->whereNull('puc.deleted_at')
                            ->sum('puc.stock');
                        $product->update(['stock' => $variantTotal]);
                    } else {
                        $product->increment('stock', $item['quantity']);
                    }
                }
    
                /*
                =============================
                product_warehouse
                =============================
                */
    
                $updated = DB::table('product_warehouse')
                    ->where('product_id', $item['product_id'])
                    ->where('warehouse_id', $request->warehouse_id)
                    ->increment('stock', $item['quantity']);
    
                if (!$updated) {
                    DB::table('product_warehouse')->insert([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $request->warehouse_id,
                        'stock' => $item['quantity'],
                    ]);
                }
            }        
            
            /*
            =============================
            ✅ الخزنة
            =============================
            */
            if (
                $request->payment_method === 'cash' &&
                $request->paid_amount > 0 &&
                $request->treasury_id
            ) {
    
                $treasury = Treasury::lockForUpdate()->find($request->treasury_id);
    
                if (!$treasury || $treasury->balance < $request->paid_amount) {
                    throw new \Exception('رصيد الخزنة غير كافي');
                }
    
                $treasury->decrement('balance', $request->paid_amount);
    
                Transfer::create([
                    'type' => 'treasury_withdraw',
                    'treasury_id' => $request->treasury_id,
                    'purchase_invoice_id' => $invoice->id,
                    'amount' => $request->paid_amount,
                    'notes' => "دفعة لفاتورة مشتريات رقم {$invoice->invoice_number}",
                ]);
            }
    
            DB::commit();

            $invoice->loadSum('purchaseReturns', 'total_amount');
    
            return response()->json([
                'data' => new PurchaseInvoiceResource(
                    $invoice->load(
                        'supplier',
                        'branch',
                        'warehouse',
                        'currency',
                        'tax',
                        'treasury',
                        'items.product',
                        'items.unit',
                        'items.color'
                    )
                ),
                'result' => 'Success',
                'message' => 'Purchase invoice created successfully',
                'status' => 200,
            ]);
    
        } catch (\Exception $e) {
    
            DB::rollBack();
    
            Log::error('Purchase invoice creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
    
            return response()->json([
                'result' => 'Error',
                'message' => 'Failed to create purchase invoice',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
    
    // ========== show ==========
    public function show($id)
    {
        try {
            $invoice = PurchaseInvoice::withSum('purchaseReturns', 'total_amount')->with([
                'supplier',
                'branch',
                'warehouse',
                'currency',
                'tax',
                'treasury',
                'items.product',
                'items.unit',
                'items.color'
            ])->findOrFail($id);

            return response()->json([
                'data' => new PurchaseInvoiceResource($invoice),
                'result' => 'Success',
                'message' => 'Purchase invoice fetched successfully',
                'status' => 200,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'result' => 'Error',
                'message' => 'Purchase invoice not found',
                'status' => 404,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'result' => 'Error',
                'message' => $e->getMessage(),
                'status' => 500,
            ]);
        }
    }

    // ========== index ==========
    public function index(Request $request)
    {
        try {
            $filters = $request->input('filters', []);
            $orderBy = $request->input('orderBy', 'id');
            $orderDir = $request->input('orderByDirection', 'desc');
            $perPage = $request->input('perPage', 10);
            $paginate = $request->boolean('paginate', true);

            $query = PurchaseInvoice::withSum('purchaseReturns', 'total_amount')->with([
                'supplier',
                'branch',
                'warehouse',
                'currency',
                'tax',
                'treasury',
                'items.product',
                'items.unit',
                'items.color'
            ]);

            // تطبيق الفلاتر
            if (!empty($filters['invoice_number'])) {
                $query->where('invoice_number', 'like', '%' . $filters['invoice_number'] . '%');
            }

            if (!empty($filters['supplier_id'])) {
                $query->where('supplier_id', $filters['supplier_id']);
            }

            if (!empty($filters['branch_id'])) {
                $query->where('branch_id', $filters['branch_id']);
            }

            if (!empty($filters['warehouse_id'])) {
                $query->where('warehouse_id', $filters['warehouse_id']);
            }

            if (!empty($filters['treasury_id'])) {
                $query->where('treasury_id', $filters['treasury_id']);
            }

            if (!empty($filters['payment_method'])) {
                $query->where('payment_method', $filters['payment_method']);
            }

            if (!empty($filters['currency_id'])) {
                $query->where('currency_id', $filters['currency_id']);
            }

            if (!empty($filters['date_from'])) {
                $query->whereDate('invoice_date', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('invoice_date', '<=', $filters['date_to']);
            }

            // ================= SORT =================
            $query->orderBy($orderBy, $orderDir);

            // ================= PAGINATION =================
            if ($paginate) {
                $invoices = $query->paginate($perPage);

                return response()->json([
                    'data' => PurchaseInvoiceResource::collection($invoices->items()),
                    'links' => [
                        'first' => $invoices->url(1),
                        'last' => $invoices->url($invoices->lastPage()),
                        'prev' => $invoices->previousPageUrl(),
                        'next' => $invoices->nextPageUrl(),
                    ],
                    'meta' => [
                        'current_page' => $invoices->currentPage(),
                        'from' => $invoices->firstItem(),
                        'last_page' => $invoices->lastPage(),
                        'per_page' => $invoices->perPage(),
                        'total' => $invoices->total(),
                    ],
                    'result' => 'Success',
                    'message' => 'Purchase invoices fetched successfully',
                    'status' => 200,
                ]);
            }

            // ================= NON PAGINATED =================
            $invoices = $query->get();

            return response()->json([
                'data' => PurchaseInvoiceResource::collection($invoices),
                'result' => 'Success',
                'message' => 'Purchase invoices fetched successfully',
                'status' => 200,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'result' => 'Error',
                'message' => $e->getMessage(),
                'status' => 500,
            ]);
        }
    }
    
    
    // ========== pay ==========
    public function pay(Request $request, PurchaseInvoice $invoice)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01'
        ]);
    
        $newPaid = $invoice->paid_amount + $data['amount'];
    
        if ($newPaid > $invoice->total_amount) {
            return response()->json([
                'message' => 'Amount exceeds total invoice value'
            ], 422);
        }
    
        $invoice->update([
            'paid_amount' => $newPaid
        ]);
    
        return response()->json([
            'message' => 'Payment updated successfully',
            'invoice' => $invoice,
            'remaining' => $invoice->remaining_amount
        ]);
    }

    // ========== update ==========
    public function update(PurchaseInvoiceRequest $request, $id)
    {
        DB::beginTransaction();

        try {
            // جلب الفاتورة القديمة
            $invoice = PurchaseInvoice::with('items')->findOrFail($id);

            // حفظ البيانات القديمة للمقارنة
            $oldPaidAmount = $invoice->paid_amount;
            $oldTreasuryId = $invoice->treasury_id;
            $oldPaymentMethod = $invoice->payment_method;

            $defaultTaxRate = $request->tax_id
                ? $this->parseTaxRate(Tax::findOrFail($request->tax_id)->rate)
                : 0;
            $totals = $this->calculateInvoiceTotals($request->items, $defaultTaxRate);
            $subtotal = $totals['subtotal'];
            $discountTotal = $totals['discount_total'];
            $taxTotal = $totals['tax_total'];
            $total = $totals['total'];
            $remaining = $total - ($request->paid_amount ?? 0);

            if (($request->paid_amount ?? 0) > $total) {
                throw new \Exception('المبلغ المدفوع أكبر من إجمالي الفاتورة');
            }

            // 1️⃣ **عكس حركات المخزون القديمة**
            foreach ($invoice->items as $oldItem) {
                $product = Product::find($oldItem->product_id);
                if ($product) {
                    $product->decrement('stock', $oldItem->quantity);
                }

                DB::table('product_warehouse')
                    ->where('product_id', $oldItem->product_id)
                    ->where('warehouse_id', $invoice->warehouse_id)
                    ->decrement('stock', $oldItem->quantity);
            }

            // 2️⃣ **حذف الأصناف القديمة**
            PurchaseInvoiceItem::where('purchase_invoice_id', $invoice->id)->delete();

            // 3️⃣ **تحديث الفاتورة**
            $invoice->update([
                'supplier_id' => $request->supplier_id,
                'branch_id' => $request->branch_id,
                'warehouse_id' => $request->warehouse_id,
                'currency_id' => $request->currency_id,
                'tax_id' => $request->tax_id,
                'treasury_id' => $request->treasury_id,
                'invoice_date' => $request->invoice_date,
                'due_date' => $request->due_date,
                'payment_method' => $request->payment_method,
                'note' => $request->note,
                'paid_amount' => $request->paid_amount ?? 0,
                'remaining_amount' => $remaining,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'total_amount' => $total,
            ]);

            // 4️⃣ **إضافة الأصناف الجديدة**
            foreach ($request->items as $index => $item) {
                PurchaseInvoiceItem::create([
                    'purchase_invoice_id' => $invoice->id,
                    'product_id' => $item['product_id'],
                    // ❌ شيل product_variant_id
                    // 'product_variant_id' => $item['product_variant_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'product_unit_id' => $item['unit_id'] ?? null,
                    'color_id' => $item['color_id'] ?? null,
                    'price' => $item['price'],
                    'discount' => $item['discount'] ?? 0,
                    'tax' => $totals['items'][$index]['tax_rate'],
                    'total' => $totals['items'][$index]['total'],
                ]);

                $product = Product::find($item['product_id']);
                if ($product) {
                    $product->increment('stock', $item['quantity']);
                }

                $pivot = DB::table('product_warehouse')
                    ->where('product_id', $item['product_id'])
                    ->where('warehouse_id', $request->warehouse_id)
                    ->first();

                if ($pivot) {
                    DB::table('product_warehouse')
                        ->where('product_id', $item['product_id'])
                        ->where('warehouse_id', $request->warehouse_id)
                        ->increment('stock', $item['quantity']);
                } else {
                    DB::table('product_warehouse')->insert([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $request->warehouse_id,
                        'stock' => $item['quantity'],
                    ]);
                }
            }

            // 5️⃣ **معالجة الخزينة**
            TreasuryTransaction::where('reference_type', PurchaseInvoice::class)
                ->where('reference_id', $invoice->id)
                ->delete();

            if ($oldPaymentMethod === 'cash' && $oldPaidAmount > 0 && $oldTreasuryId) {
                $oldTreasury = Treasury::find($oldTreasuryId);
                if ($oldTreasury) {
                    $oldTreasury->increment('balance', $oldPaidAmount);
                }
            }

            if ($request->payment_method === 'cash' && $request->paid_amount > 0 && $request->treasury_id) {
                TreasuryTransaction::create([
                    'treasury_id' => $request->treasury_id,
                    'reference_type' => PurchaseInvoice::class,
                    'reference_id' => $invoice->id,
                    'type' => 'out',
                    'amount' => $request->paid_amount,
                    'description' => "تحديث فاتورة مشتريات رقم {$invoice->invoice_number}",
                    'created_at' => now(),
                ]);

                $treasury = Treasury::find($request->treasury_id);
                if ($treasury) {
                    $treasury->decrement('balance', $request->paid_amount);
                }
            }

            DB::commit();

            $invoice->loadSum('purchaseReturns', 'total_amount');

            return response()->json([
                'data' => new PurchaseInvoiceResource(
                    $invoice->load(
                        'supplier',
                        'branch',
                        'warehouse',
                        'currency',
                        'tax',
                        'treasury',
                        'items.product',
                        'items.unit',
                        'items.color'
                    )
                ),
                'result' => 'Success',
                'message' => 'Purchase invoice updated successfully',
                'status' => 200,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Purchase invoice update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'invoice_id' => $id
            ]);

            return response()->json([
                'result' => 'Error',
                'message' => 'Failed to update purchase invoice',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

}
