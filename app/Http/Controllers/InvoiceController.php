<?php

namespace App\Http\Controllers;

use App\Http\Resources\InvoiceResource;
use App\Models\Admin;
use App\Models\CashierShift;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\LoyaltySetting;
use App\Models\Product;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    public function invoiceIndex(Request $request)
    {
        try {
            $filters = $request->input('filters', []);
            $orderBy = $request->input('orderBy', 'id');
            $orderByDirection = $request->input('orderByDirection', 'desc');
            $perPage = $request->input('perPage', 10);
            $paginate = $request->boolean('paginate', true);

            $query = Invoice::with([
                'customer',
                'items.product',
                'cashier',
                'treasury',
                'salesRepresentative',
                'shift'
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

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['cashier_id'])) {
                $query->where('cashier_id', $filters['cashier_id']);
            }

            if (!empty($filters['treasury_id'])) {
                $query->where('treasury_id', $filters['treasury_id']);
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
            // PAGINATION MODE
            // =========================
            if ($paginate) {
                $invoices = $query->paginate($perPage);

                return response()->json([
                    'data' => InvoiceResource::collection($invoices->items()),
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
                    'message' => 'Invoices fetched successfully',
                    'status' => 200,
                ]);
            }

            // =========================
            // NON PAGINATED MODE
            // =========================
            $invoices = $query->get();

            return response()->json([
                'data' => InvoiceResource::collection($invoices),
                'links' => null,
                'meta' => null,
                'result' => 'Success',
                'message' => 'Invoices fetched successfully',
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

public function store(Request $request)
{
    $request->validate([
        'customer_id' => 'nullable|integer|exists:customers,id',
        'redeem_points' => 'nullable|integer|min:0',
        'total' => 'nullable|numeric|min:0',
        'payments' => 'present|array',
        'payments.*.method' => 'required|in:cash,card,wallet',
        'payments.*.amount' => 'required|numeric|min:0',
    ]);

    DB::beginTransaction();
    
    try {
        $user = auth()->user();

        // ✅ جلب وردية المستخدم الحالي
        $shift = CashierShift::where('status', 'open')
            ->where(function ($q) use ($user) {
                if ($user instanceof Admin) {
                    $q->where('admin_id', $user->id);
                } elseif ($user instanceof Employee) {
                    $q->where('employee_id', $user->id);
                }
            })
            ->latest('opened_at')
            ->first();

        if (!$shift) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'لا توجد وردية مفتوحة لك. يرجى فتح وردية أولاً.'
            ], 400);
        }

        $employee = $user instanceof Employee
            ? $user->loadMissing('treasury')
            : $shift->employee()->with('treasury')->first();
        $cashierId = $employee?->id;
        $treasuryId = $employee?->treasury_id;

        $treasury = $treasuryId ? Treasury::find($treasuryId) : null;
        if (!$employee || !$treasury) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'تعذر تحديد خزينة الموظف المرتبط بالوردية. تحقق من تعيين خزينة للموظف ثم أعد المحاولة.'
            ], 400);
        }

        // ============================================================
        // ✅ حساب المجاميع
        // ============================================================
        $total = collect($request->items)
            ->sum(fn ($item) => $item['price'] * $item['quantity']);

        $externalPaid = collect($request->payments)->sum('amount');
        $cashPaid = collect($request->payments)->where('method', 'cash')->sum('amount');
        $cardPaid = collect($request->payments)->where('method', 'card')->sum('amount');
        $walletPaid = collect($request->payments)
            ->where('method', 'wallet')
            ->sum('amount');

        $invoiceNumber = 'INV-' . now()->format('Ymd') . '-' . rand(1000, 9999);
        $discountPercentage = $request->discount_percentage ?? 0;
        $discountAmount = ($total * $discountPercentage) / 100;
        $netTotal = $total - $discountAmount;
        $invoiceTotal = $request->has('total') ? (float) $request->input('total') : $netTotal;
        $redeemedPoints = (int) $request->input('redeem_points', 0);
        $loyaltyDiscountAmount = 0;
        $loyaltySetting = null;

        if ($redeemedPoints > 0) {
            $customer = $request->customer_id
                ? Customer::whereKey($request->customer_id)->lockForUpdate()->first()
                : null;
            $loyaltySetting = LoyaltySetting::first();

            if (!$customer) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'يجب اختيار عميل لاستخدام نقاط الولاء.',
                ], 422);
            }

            if (!$loyaltySetting || (float) $loyaltySetting->point_value <= 0) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'إعدادات استبدال نقاط الولاء غير متاحة.',
                ], 422);
            }

            if ((int) ($customer->point ?? 0) < $redeemedPoints) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'رصيد نقاط العميل غير كافٍ.',
                ], 422);
            }

            if ($redeemedPoints * (float) $loyaltySetting->point_value > 100) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => 'قيمة الخصم بنقاط الولاء لا يمكن أن تتجاوز 100٪.',
                ], 422);
            }

            $loyaltyDiscountAmount = round(
                $invoiceTotal * $redeemedPoints * (float) $loyaltySetting->point_value / 100,
                2
            );
            $customer->point = (int) $customer->point - $redeemedPoints;
            $customer->save();
        }

        $paid = $externalPaid + $loyaltyDiscountAmount;

        // ============================================================
        // ✅ إنشاء الفاتورة
        // ============================================================
        $invoice = Invoice::create([
            'invoice_number'   => $invoiceNumber,
            'customer_id'      => $request->customer_id,
            'sales_representative_id' => $request->sales_representative_id,
            'cashier_id'       => $cashierId,
            'treasury_id'      => $treasuryId,
            'cashier_shift_id' => $shift->id,
            'total_amount'     => $invoiceTotal,
            'discount_percentage' => $discountPercentage,
            'discount_amount'  => $discountAmount,
            'loyalty_points_redeemed' => $redeemedPoints,
            'loyalty_discount_amount' => $loyaltyDiscountAmount,
            'paid_amount'      => $paid,
            'remaining_amount' => max(0, $invoiceTotal - $paid),
            'status'           => $paid >= $invoiceTotal ? 'paid' : 'partial',
        ]);

        // ============================================================
        // ✅ إضافة العناصر
        // ============================================================
        foreach ($request->items as $item) {
            $product = Product::whereKey($item['product_id'])->lockForUpdate()->first();

            if (!$product) {
                DB::rollBack();
                return response()->json([
                    'status' => false,
                    'message' => "المنتج ID {$item['product_id']} غير موجود"
                ], 400);
            }

            $quantity = (int) $item['quantity'];
            $unitId = !empty($item['unit_id']) ? (int) $item['unit_id'] : null;
            $colorId = !empty($item['color_id']) ? (int) $item['color_id'] : null;
            $productUnit = null;
            $colorStocks = collect();

            $hasColorVariants = DB::table('product_unit_colors as puc')
                ->join('product_units as pu', 'pu.id', '=', 'puc.product_unit_id')
                ->where('pu.product_id', $product->id)
                ->whereNull('puc.deleted_at')
                ->exists();

            if ($hasColorVariants && (!$unitId || !$colorId)) {
                throw new \RuntimeException("يجب تحديد المقاس واللون للمنتج '{$product->name}'");
            }

            if ($unitId !== null) {
                $productUnit = DB::table('product_units')
                    ->where('product_id', $product->id)
                    ->where('unit_id', $unitId)
                    ->lockForUpdate()
                    ->first();

                if (!$productUnit) {
                    throw new \RuntimeException("المقاس المحدد غير مرتبط بالمنتج '{$product->name}'");
                }
            }

            if ($colorId !== null) {
                $colorStocks = DB::table('product_unit_colors')
                    ->where('product_unit_id', $productUnit->id)
                    ->where('color_id', $colorId)
                    ->whereNull('deleted_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $available = (int) $colorStocks->sum('stock');
                if ($colorStocks->isEmpty() || $available < $quantity) {
                    throw new \RuntimeException("مخزون المقاس واللون المحددين للمنتج '{$product->name}' غير كافٍ (المتاح: {$available})");
                }
            } elseif ((int) $product->stock < $quantity) {
                throw new \RuntimeException("الكمية المتوفرة في المخزون للمنتج '{$product->name}' أقل من المطلوبة");
            }

            $invoice->items()->create([
                'product_id'   => $item['product_id'],
                'product_name' => $item['product_name'] ?? $product->name,
                'color'        => $item['color'] ?? null,
                'size'         => $item['size'] ?? null,
                'unit_id'      => $unitId,
                'color_id'     => $colorId,
                'quantity'     => $item['quantity'],
                'price'        => $item['price'],
                'cost_price'   => $productUnit?->cost_price ?? $product->cost ?? 0,
                'total'        => $item['price'] * $item['quantity'],
            ]);

            if ($colorStocks->isNotEmpty()) {
                $remainingToDeduct = $quantity;
                foreach ($colorStocks as $colorStock) {
                    if ($remainingToDeduct <= 0) {
                        break;
                    }

                    $deduct = min($remainingToDeduct, (int) $colorStock->stock);
                    if ($deduct > 0) {
                        DB::table('product_unit_colors')
                            ->where('id', $colorStock->id)
                            ->decrement('stock', $deduct);
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
        }

        // ============================================================
        // ✅ إضافة المدفوعات
        // ============================================================
        foreach ($request->payments as $payment) {
            $invoice->payments()->create([
                'method' => $payment['method'],
                'amount' => $payment['amount'],
            ]);
        }

        // ============================================================
        // ✅ إيداع المدفوعات النقدية في الخزينة
        // ============================================================
        if ($cashPaid > 0 && $treasuryId) {
            $treasury = Treasury::find($treasuryId);
            if ($treasury) {
                $treasury->increment('balance', $cashPaid);

                TreasuryTransaction::create([
                    'treasury_id' => $treasuryId,
                    'reference_type' => Invoice::class,
                    'reference_id' => $invoice->id,
                    'type' => 'in',
                    'amount' => $cashPaid,
                    'description' => "فاتورة مبيعات رقم {$invoice->invoice_number}",
                    'created_by' => $user?->id,
                ]);
            }
        }

        // ============================================================
        // ✅ ✅ ✅ تحديث نقاط الولاء (مع Logging)
        // ============================================================
        \Log::info('⭐ ========== LOYALTY POINTS START ==========');
        \Log::info('📊 Paid Amount: ' . $paid);
        \Log::info('👤 Customer ID: ' . $request->customer_id);

        $loyaltySetting = $loyaltySetting ?? LoyaltySetting::first();
        \Log::info('📊 Loyalty Settings:', [
            'exists' => $loyaltySetting ? 'Yes' : 'No',
            'point_value' => $loyaltySetting?->point_value
        ]);

        if ($loyaltySetting && $loyaltySetting->point_value > 0) {
            \Log::info('✅ Loyalty is active');
            
            $customer = Customer::find($request->customer_id);
            \Log::info('👤 Customer found:', [
                'exists' => $customer ? 'Yes' : 'No',
                'name' => $customer?->name,
                'current_points' => $customer?->point ?? 0
            ]);
            
            if ($customer) {
                $eligiblePaid = min($externalPaid, max(0, $invoiceTotal - $loyaltyDiscountAmount));
                $earnedPoints = (float) $loyaltySetting->points > 0
                    ? floor($eligiblePaid / $loyaltySetting->points)
                    : 0;
                $oldPoints = $customer->point ?? 0;
                $newPoints = $oldPoints + $earnedPoints;
                
                \Log::info('🧮 Points Calculation:', [
                    'eligible_paid' => $eligiblePaid,
                    'points_per_reward' => $loyaltySetting->points,
                    'earned_points' => $earnedPoints,
                    'old_points' => $oldPoints,
                    'new_points' => $newPoints
                ]);
                
                $customer->point = $newPoints;
                $customer->last_paid_amount = $eligiblePaid;
                $customer->save();
                
                \Log::info('✅ Points saved successfully!', [
                    'customer_id' => $customer->id,
                    'new_points' => $customer->point
                ]);
            } else {
                \Log::error('❌ Customer not found!');
            }
        } else {
            \Log::error('❌ Loyalty settings not found or point_value = 0');
        }

        \Log::info('⭐ ========== LOYALTY POINTS END ==========');

        // ============================================================
        // ✅ تحديث مبيعات الوردية
        // ============================================================
        $shift->update([
            'cash_sales'   => ($shift->cash_sales ?? 0) + $cashPaid,
            'card_sales'   => ($shift->card_sales ?? 0) + $cardPaid,
            'wallet_sales' => ($shift->wallet_sales ?? 0) + $walletPaid,
        ]);

        DB::commit();

        return response()->json([
            'status'  => true,
            'message' => 'Invoice created successfully',
            'data'    => new InvoiceResource(
                $invoice->load([
                    'items',
                    'payments',
                    'customer',
                    'shift',
                    'salesRepresentative',
                    'cashier',
                    'treasury'
                ])
            )
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        
        Log::error('❌ Invoice Store Error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'request' => $request->all()
        ]);

        return response()->json([
            'status'  => false,
            'message' => 'حدث خطأ أثناء إنشاء الفاتورة: ' . $e->getMessage(),
        ], $e instanceof \RuntimeException ? 400 : 500);
    }
}

    public function searchByInvoiceNumber(Request $request)
    {
        $request->validate([
            'invoice_number' => 'required|string'
        ]);

        try {
            $invoice = Invoice::with([
                'items.product',
                'customer',
                'cashier',
                'treasury',
                'salesRepresentative'
            ])
                ->where('invoice_number', $request->query('invoice_number'))
                ->first();

            if (!$invoice) {
                return response()->json([
                    'status'  => false,
                    'message' => "لا توجد فاتورة برقم {$request->query('invoice_number')}"
                ], 404);
            }

            return response()->json([
                'status'  => true,
                'message' => 'تم العثور على الفاتورة',
                'data'    => new InvoiceResource($invoice)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'حدث خطأ أثناء البحث',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $invoice = Invoice::with([
                'items',
                'payments',
                'customer',
                'shift',
                'salesRepresentative',
                'cashier',
                'treasury'
            ])->findOrFail($id);

            return response()->json([
                'status' => true,
                'data' => new InvoiceResource($invoice)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
