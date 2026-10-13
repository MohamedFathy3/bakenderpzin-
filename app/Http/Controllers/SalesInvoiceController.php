<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSalesInvoiceRequest;
use App\Http\Resources\SalesInvoiceResource;
use App\Models\Customer;
use App\Models\LoyaltySetting;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\Treasury;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesInvoiceController extends Controller
{
    /**
     * Store a newly created sales invoice in storage.
     */
    public function store(StoreSalesInvoiceRequest $request)
    {
        DB::beginTransaction();

        try {
            $invoiceNumber = 'SI-' . now()->format('Ymd') . '-' . rand(1000, 9999);

            $subtotal = 0;
            $itemsData = [];

            foreach ($request->items as $item) {
                $product = Product::whereKey($item['product_id'])->lockForUpdate()->firstOrFail();
                $quantity = (int) $item['quantity'];
                $unitId = $item['unit_id'] ?? null;
                $colorId = $item['color_id'] ?? null;
                $productUnit = null;
                $colorStocks = collect();

                if ($unitId !== null) {
                    $productUnit = DB::table('product_units')
                        ->where('product_id', $product->id)
                        ->where('unit_id', $unitId)
                        ->first();
                }

                $hasColorVariants = DB::table('product_unit_colors as puc')
                    ->join('product_units as pu', 'pu.id', '=', 'puc.product_unit_id')
                    ->where('pu.product_id', $product->id)
                    ->whereNull('puc.deleted_at')
                    ->exists();

                if ($hasColorVariants && ($unitId === null || $colorId === null)) {
                    throw new \RuntimeException("يجب تحديد الوحدة واللون للمنتج '{$product->name}'");
                }

                if ($colorId !== null) {
                    if (!$productUnit) {
                        throw new \RuntimeException("الوحدة المحددة غير مرتبطة بالمنتج '{$product->name}'");
                    }

                    $colorStocks = DB::table('product_unit_colors')
                        ->where('product_unit_id', $productUnit->id)
                        ->where('color_id', $colorId)
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->orderBy('id')
                        ->get();

                    $available = (int) $colorStocks->sum('stock');
                    if ($colorStocks->isEmpty() || $available < $quantity) {
                        throw new \RuntimeException("رصيد اللون المحدد للمنتج '{$product->name}' غير كافٍ (المتاح: {$available})");
                    }
                }

                if ($colorStocks->isEmpty() && (int) $product->stock < $quantity) {
                    throw new \RuntimeException("إجمالي المخزون للمنتج '{$product->name}' أقل من الكمية المطلوبة");
                }

                // حساب سعر المنتج بعد خصمه الفردي
                $itemDiscountPercentage = $item['discount_percentage'] ?? 0;
                $itemDiscountAmount = ($item['price'] * $item['quantity'] * $itemDiscountPercentage) / 100;
                $itemTotalAfterDiscount = ($item['price'] * $item['quantity']) - $itemDiscountAmount;

                $itemsData[] = [
                    'product_id' => $item['product_id'],
                    'product_unit_id' => $unitId,
                    'color_id' => $colorId,
                    'quantity' => $quantity,
                    'price' => $item['price'],
                    'cost_price' => $productUnit?->cost_price ?? $product->cost ?? 0,
                    'discount_percentage' => $itemDiscountPercentage,
                    'discount_amount' => $itemDiscountAmount,
                    'total' => $itemTotalAfterDiscount,
                ];

                $subtotal += $itemTotalAfterDiscount;

                // خصم الكمية من المخزون
                if ($colorStocks->isNotEmpty()) {
                    $remainingToDeduct = $quantity;
                    foreach ($colorStocks as $colorStock) {
                        if ($remainingToDeduct <= 0) {
                            break;
                        }
                        $deduct = min($remainingToDeduct, (int) $colorStock->stock);
                        if ($deduct > 0) {
                            DB::table('product_unit_colors')->where('id', $colorStock->id)->decrement('stock', $deduct);
                            $remainingToDeduct -= $deduct;
                        }
                    }

                    $variantTotal = DB::table('product_unit_colors as puc')
                        ->join('product_units as pu', 'pu.id', '=', 'puc.product_unit_id')
                        ->where('pu.product_id', $product->id)
                        ->whereNull('puc.deleted_at')
                        ->sum('puc.stock');

                    $product->update(['stock' => $variantTotal]);
                } else {
                    $product->decrement('stock', $quantity);
                }

                if ($request->warehouse_id) {
                    DB::table('product_warehouse')
                        ->where('product_id', $product->id)
                        ->where('warehouse_id', $request->warehouse_id)
                        ->decrement('stock', $quantity);
                }
            }

            // خصم الفاتورة
            $discountPercentage = $request->discount_percentage ?? 0;
            $discountAmount = ($subtotal * $discountPercentage) / 100;
            $netTotal = $subtotal - $discountAmount;

            // إنشاء الفاتورة
            $invoice = SalesInvoice::create([
                'invoice_number' => $invoiceNumber,
                'customer_id' => $request->customer_id,
                'treasury_id' => $request->treasury_id,
                'sales_representative_id' => $request->sales_representative_id,
                'branch_id' => $request->branch_id,
                'warehouse_id' => $request->warehouse_id,
                'currency_id' => $request->currency_id,
                'tax_id' => $request->tax_id,
                'payment_method' => $request->payment_method,
                'invoice_date' => $request->invoice_date ?? now()->format('Y-m-d'),
                'due_date' => $request->due_date,
                'note' => $request->note,
                'total_amount' => $subtotal,
                'discount_percentage' => $discountPercentage,
                'discount_amount' => $discountAmount,
                'net_total' => $netTotal,
            ]);

            // إنشاء عناصر الفاتورة
            foreach ($itemsData as $itemData) {
                SalesInvoiceItem::create(array_merge(
                    ['sales_invoice_id' => $invoice->id],
                    $itemData
                ));
            }

            // إضافة الرصيد للخزنة
            if ($request->treasury_id) {
                $treasury = Treasury::findOrFail($request->treasury_id);
                $treasury->increment('balance', $netTotal);
            }

            // ============================================================
            // ✅ ✅ ✅ إضافة نقاط الولاء
            // ============================================================
            $this->updateLoyaltyPoints($request->customer_id, $netTotal);

            DB::commit();

            return new SalesInvoiceResource(
                $invoice->load(
                    'items.product',
                    'items.unit',
                    'items.color',
                    'customer',
                    'salesRepresentative',
                    'branch',
                    'warehouse',
                    'currency',
                    'tax'
                )
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('❌ Sales Invoice Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to create invoice',
                'error' => $e->getMessage()
            ], $e instanceof \RuntimeException ? 400 : 500);
        }
    }

    // ============================================================
    // ✅ دالة تحديث نقاط الولاء
    // ============================================================
    private function updateLoyaltyPoints($customerId, $paidAmount)
    {
        Log::info('⭐ ========== LOYALTY POINTS START (Sales Invoice) ==========');
        Log::info('📊 Customer ID: ' . $customerId);
        Log::info('💰 Paid Amount: ' . $paidAmount);

        try {
            // 1️⃣ جلب إعدادات الولاء
            $loyaltySetting = LoyaltySetting::first();
            
            Log::info('📊 Loyalty Settings:', [
                'exists' => $loyaltySetting ? 'Yes' : 'No',
                'point_value' => $loyaltySetting?->point_value,
                'silver' => $loyaltySetting?->silver,
                'gold' => $loyaltySetting?->gold,
                'platinum' => $loyaltySetting?->platinum
            ]);

            if (!$loyaltySetting || $loyaltySetting->point_value <= 0) {
                Log::warning('⚠️ Loyalty settings not found or point_value = 0');
                return;
            }

            // 2️⃣ جلب العميل
            $customer = Customer::find($customerId);
            
            if (!$customer) {
                Log::error('❌ Customer not found for ID: ' . $customerId);
                return;
            }

            Log::info('👤 Customer found:', [
                'id' => $customer->id,
                'name' => $customer->name,
                'current_points' => $customer->point ?? 0
            ]);

            // 3️⃣ حساب النقاط (ضرب المبلغ في قيمة النقطة)
            $earnedPoints = floor($paidAmount * $loyaltySetting->point_value);
            $oldPoints = $customer->point ?? 0;
            $newPoints = $oldPoints + $earnedPoints;

            Log::info('🧮 Points Calculation:', [
                'paid_amount' => $paidAmount,
                'point_value' => $loyaltySetting->point_value,
                'earned_points' => $earnedPoints,
                'old_points' => $oldPoints,
                'new_points' => $newPoints
            ]);

            // 4️⃣ تحديث النقاط
            $customer->point = $newPoints;
            $customer->last_paid_amount = $paidAmount;
            $customer->save();

            Log::info('✅ Points saved successfully!', [
                'customer_id' => $customer->id,
                'new_points' => $customer->point
            ]);

            // 5️⃣ تحديث مستوى العميل
            $this->updateCustomerLevel($customer, $loyaltySetting);

        } catch (\Exception $e) {
            Log::error('❌ Error updating loyalty points: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }

        Log::info('⭐ ========== LOYALTY POINTS END ==========');
    }

    // ============================================================
    // ✅ دالة تحديث مستوى العميل
    // ============================================================
    private function updateCustomerLevel($customer, $loyaltySetting)
    {
        $points = $customer->point ?? 0;
        $level = 'bronze';

        if ($points >= $loyaltySetting->platinum) {
            $level = 'platinum';
        } elseif ($points >= $loyaltySetting->gold) {
            $level = 'gold';
        } elseif ($points >= $loyaltySetting->silver) {
            $level = 'silver';
        }

        $customer->level = $level;
        $customer->save();

        Log::info('🏆 Customer level updated:', [
            'customer_id' => $customer->id,
            'level' => $level,
            'points' => $points
        ]);

        return $level;
    }

    // ============================================================
    // ✅ جلب جميع فواتير المبيعات
    // ============================================================
    public function invoiceIndex(Request $request)
    {
        try {
            $filters = $request->input('filters', []);
            $orderBy = $request->input('orderBy', 'id');
            $orderByDirection = $request->input('orderByDirection', 'desc');
            $perPage = $request->input('perPage', 10);
            $paginate = $request->boolean('paginate', true);

            $query = SalesInvoice::with([
                'customer',
                'items.product',
                'salesRepresentative',
                'branch',
                'warehouse',
                'currency',
                'tax',
                'treasury'
            ]);

            // =========================
            // FILTERS
            // =========================
            if (!empty($filters['invoice_number'])) {
                $query->where('invoice_number', 'like', '%' . $filters['invoice_number'] . '%');
            }

            if (!empty($filters['customer_id'])) {
                $query->where('customer_id', $filters['customer_id']);
            }

            if (!empty($filters['sales_representative_id'])) {
                $query->where('sales_representative_id', $filters['sales_representative_id']);
            }

            if (!empty($filters['branch_id'])) {
                $query->where('branch_id', $filters['branch_id']);
            }

            if (!empty($filters['warehouse_id'])) {
                $query->where('warehouse_id', $filters['warehouse_id']);
            }

            if (!empty($filters['payment_method'])) {
                $query->where('payment_method', $filters['payment_method']);
            }

            if (!empty($filters['currency_id'])) {
                $query->where('currency_id', $filters['currency_id']);
            }

            if (!empty($filters['tax_id'])) {
                $query->where('tax_id', $filters['tax_id']);
            }

            if (!empty($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }

            // =========================
            // SORT
            // =========================
            $query->orderBy($orderBy, $orderByDirection);

            // =========================
            // PAGINATION
            // =========================
            if ($paginate) {
                $invoices = $query->paginate($perPage);

                return response()->json([
                    'data' => SalesInvoiceResource::collection($invoices->items()),
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
                        'path' => $invoices->path(),
                        'per_page' => $invoices->perPage(),
                        'to' => $invoices->lastItem(),
                        'total' => $invoices->total(),
                    ],
                    'result' => 'Success',
                    'message' => 'Sales invoices fetched successfully',
                    'status' => 200,
                ]);
            }

            // =========================
            // NON PAGINATED
            // =========================
            $invoices = $query->get();

            return response()->json([
                'data' => SalesInvoiceResource::collection($invoices),
                'links' => null,
                'meta' => null,
                'result' => 'Success',
                'message' => 'Sales invoices fetched successfully',
                'status' => 200,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'result' => 'Error',
                'message' => $e->getMessage(),
                'status' => 500,
            ], 500);
        }
    }

    // ============================================================
    // ✅ عرض فاتورة مبيعات واحدة
    // ============================================================
    public function show($id)
    {
        try {
            $invoice = SalesInvoice::with([
                'items.product',
                'items.unit',
                'items.color',
                'customer',
                'salesRepresentative',
                'branch',
                'warehouse',
                'currency',
                'tax',
                'treasury',
            ])->findOrFail($id);

            return response()->json([
                'data' => new SalesInvoiceResource($invoice),
                'result' => 'Success',
                'message' => 'Sales invoice fetched successfully',
                'status' => 200,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'result' => 'Error',
                'message' => 'Sales invoice not found',
                'status' => 404,
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'result' => 'Error',
                'message' => $e->getMessage(),
                'status' => 500,
            ], 500);
        }
    }
}
