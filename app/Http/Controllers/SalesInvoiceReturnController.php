<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSalesInvoiceReturnRequest;
use App\Http\Resources\SalesInvoiceReturnResource;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceReturn;
use App\Models\SalesInvoiceReturnItem;
use App\Models\CashierShift;
use App\Models\Currency;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\LoyaltySetting;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesInvoiceReturnController extends Controller
{
    // ============================================================
    // ✅ INDEX - جلب جميع المرتجعات
    // ============================================================
    public function index(Request $request)
    {
        try {
            $filters = $request->input('filters', []);
            $orderBy = $request->input('orderBy', 'id');
            $orderByDirection = $request->input('orderByDirection', 'desc');
            $perPage = $request->input('perPage', 10);
            $paginate = $request->boolean('paginate', true);

            $query = SalesInvoiceReturn::with([
                'invoice.customer',
                'invoice.currency',
                'items.product',
                'items.unit',
                'items.color',
                'cashier',
                'shift',
                'treasury'
            ]);

            // =========================
            // FILTERS
            // =========================

            if (!empty($filters['return_number'])) {
                $query->where('return_number', 'like', '%' . $filters['return_number'] . '%');
            }

            if (!empty($filters['invoice_number'])) {
                $query->whereHas('invoice', function ($q) use ($filters) {
                    $q->where('invoice_number', 'like', '%' . $filters['invoice_number'] . '%');
                });
            }

            if (!empty($filters['sales_invoice_id'])) {
                $query->where('sales_invoice_id', $filters['sales_invoice_id']);
            }

            if (!empty($filters['customer_id'])) {
                $query->whereHas('invoice', function ($q) use ($filters) {
                    $q->where('customer_id', $filters['customer_id']);
                });
            }

            if (!empty($filters['min_total'])) {
                $query->where('total_amount', '>=', $filters['min_total']);
            }

            if (!empty($filters['max_total'])) {
                $query->where('total_amount', '<=', $filters['max_total']);
            }

            if (!empty($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }

            if (!empty($filters['product_id'])) {
                $query->whereHas('items', function ($q) use ($filters) {
                    $q->where('product_id', $filters['product_id']);
                });
            }

            // =========================
            // SORT
            // =========================
            $query->orderBy($orderBy, $orderByDirection);

            // =========================
            // PAGINATION
            // =========================
            if ($paginate) {
                $returns = $query->paginate($perPage);

                return response()->json([
                    'data' => SalesInvoiceReturnResource::collection($returns->items()),
                    'links' => [
                        'first' => $returns->url(1),
                        'last' => $returns->url($returns->lastPage()),
                        'prev' => $returns->previousPageUrl(),
                        'next' => $returns->nextPageUrl(),
                    ],
                    'meta' => [
                        'current_page' => $returns->currentPage(),
                        'from' => $returns->firstItem(),
                        'last_page' => $returns->lastPage(),
                        'path' => $returns->path(),
                        'per_page' => $returns->perPage(),
                        'to' => $returns->lastItem(),
                        'total' => $returns->total(),
                    ],
                    'result' => 'Success',
                    'message' => 'Sales returns fetched successfully',
                    'status' => 200,
                ]);
            }

            $returns = $query->get();

            return response()->json([
                'data' => SalesInvoiceReturnResource::collection($returns),
                'links' => null,
                'meta' => null,
                'result' => 'Success',
                'message' => 'Sales returns fetched successfully',
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
    // ✅ STORE DIRECT RETURN - مرتجع مباشر (بدون فاتورة)
    // ============================================================
    public function storeDirectReturn(Request $request)
    {
        DB::beginTransaction();

        try {
            $user = auth()->user();

            // ✅ تحديد cashier_id و treasury_id
            $cashierId = null;
            $treasuryId = null;

            if ($user instanceof Employee) {
                $cashierId = $user->id;
                $treasuryId = $user->treasury_id;
                
                if (!$treasuryId) {
                    DB::rollBack();
                    return response()->json([
                        'status' => false,
                        'message' => 'الموظف ليس لديه خزينة مخصصة'
                    ], 400);
                }
            } elseif ($user instanceof Admin) {
                $cashierId = null;
                $mainTreasury = Treasury::where('is_main', true)->first();
                $treasuryId = $mainTreasury?->id;

                if (!$treasuryId) {
                    DB::rollBack();
                    return response()->json([
                        'status' => false,
                        'message' => 'لا توجد خزينة رئيسية'
                    ], 400);
                }
            }

            // ✅ Validation
            $validated = $request->validate([
                'supplier_id' => 'nullable|exists:suppliers,id',
                'sales_invoice_id' => 'nullable|exists:sales_invoices,id',
                'return_method' => 'required|in:cash,card,wallet,bank',
                'note' => 'nullable|string|max:500',
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.price' => 'required|numeric|min:0',
                'items.*.reason' => 'required|in:defective,wrong_item,damaged,customer_change,other',
                'items.*.discount' => 'nullable|numeric|min:0|max:100',
                'items.*.tax' => 'nullable|numeric|min:0|max:100',
                'branch_id' => 'nullable|exists:branches,id',
            ]);

            // ✅ توليد رقم مرتجع
            $returnNumber = 'DR-' . now()->format('Ymd') . '-' . str_pad(SalesInvoiceReturn::count() + 1, 4, '0', STR_PAD_LEFT);

            // ✅ حساب الإجمالي
            $totalReturn = collect($request->items)->sum(function($item) {
                $itemTotal = $item['quantity'] * $item['price'];
                $discount = isset($item['discount']) ? ($itemTotal * $item['discount']) / 100 : 0;
                $tax = isset($item['tax']) ? (($itemTotal - $discount) * $item['tax']) / 100 : 0;
                return $itemTotal - $discount + $tax;
            });

            // ✅ ربط المرتجع بالـ Shift المفتوح
            $shiftId = $request->shift_id;

            if (!$shiftId) {
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

                if ($shift) {
                    $shiftId = $shift->id;
                }
            }

            // ✅ إنشاء المرتجع
            $return = SalesInvoiceReturn::create([
                'return_number' => $returnNumber,
                'sales_invoice_id' => $request->sales_invoice_id,
                'return_method' => $request->return_method,
                'total_amount' => $totalReturn,
                'note' => $request->note,
                'supplier_id' => $request->supplier_id,
                'branch_id' => $request->branch_id,
                'shift_id' => $shiftId,
                'cashier_id' => $cashierId,
                'treasury_id' => $treasuryId,
                'created_by' => auth()->id(),
                'is_direct' => true,
            ]);

            // ✅ حساب المبالغ حسب طريقة الدفع
            $cashReturn = 0;
            $cardReturn = 0;
            $walletReturn = 0;

            $returnMethod = $request->return_method;
            
            if (in_array($returnMethod, ['cash', 'نقدي', 'نقداً', 'نقدا'])) {
                $cashReturn = $totalReturn;
            } elseif (in_array($returnMethod, ['card', 'بطاقة', 'بطاقه'])) {
                $cardReturn = $totalReturn;
            } elseif (in_array($returnMethod, ['wallet', 'محفظة', 'محفظه', 'رصيد'])) {
                $walletReturn = $totalReturn;
            }

            // ✅ إضافة المنتجات وتحديث المخزون
            foreach ($request->items as $item) {
                SalesInvoiceReturnItem::create([
                    'sales_invoice_return_id' => $return->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'total' => $item['quantity'] * $item['price'],
                    'reason' => $item['reason'],
                    'discount' => $item['discount'] ?? 0,
                    'tax' => $item['tax'] ?? 0,
                ]);

                $product = Product::find($item['product_id']);
                if ($product) {
                    $product->increment('stock', $item['quantity']);
                }
            }

            // ✅ الفلوس تخرج من الخزينة
            if ($cashReturn > 0 && $treasuryId) {
                $treasury = Treasury::find($treasuryId);
                if ($treasury) {
                    $treasury->decrement('balance', $cashReturn);

                    Log::info("💰 Treasury balance decreased from return", [
                        'treasury_id' => $treasury->id,
                        'amount' => $cashReturn,
                        'return_id' => $return->id,
                        'return_number' => $return->return_number
                    ]);
                }

                TreasuryTransaction::create([
                    'treasury_id' => $treasuryId,
                    'reference_type' => SalesInvoiceReturn::class,
                    'reference_id' => $return->id,
                    'type' => 'out',
                    'amount' => $cashReturn,
                    'description' => "مرتجع منتجات رقم {$return->return_number}",
                    'created_by' => $user?->id,
                    'created_at' => now(),
                ]);
            }

            // ✅ تحديث الـ Shift
            if ($shiftId) {
                $shift = CashierShift::find($shiftId);
                if ($shift) {
                    $shift->update([
                        'returns_amount' => ($shift->returns_amount ?? 0) + $totalReturn,
                        'cash_sales' => ($shift->cash_sales ?? 0) - $cashReturn,
                        'card_sales' => ($shift->card_sales ?? 0) - $cardReturn,
                        'wallet_sales' => ($shift->wallet_sales ?? 0) - $walletReturn,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'data' => $return->load('items.product', 'treasury'),
                'result' => 'Success',
                'message' => 'Direct return created successfully',
                'status' => 200,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Direct Return Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'result' => 'Error',
                'message' => $e->getMessage(),
                'status' => 500,
            ], 500);
        }
    }

    // ============================================================
    // ✅ SHOW - عرض مرتجع واحد
    // ============================================================
    public function show($id)
    {
        try {
            $return = SalesInvoiceReturn::with([
                'invoice.customer',
                'invoice.currency',
                'items.product',
                'items.unit',
                'items.color',
                'cashier',
                'shift',
                'treasury'
            ])->findOrFail($id);

            return response()->json([
                'data' => new SalesInvoiceReturnResource($return),
                'result' => 'Success',
                'message' => 'Sales return fetched successfully',
                'status' => 200,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'result' => 'Error',
                'message' => 'Sales return not found',
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

    // ============================================================
    // ✅ STORE RETURN - مرتجع من فاتورة موجودة
    // ============================================================
    public function storeReturn(StoreSalesInvoiceReturnRequest $request)
    {
        DB::beginTransaction();

        try {
            $invoice = SalesInvoice::whereKey($request->sales_invoice_id)
                ->lockForUpdate()
                ->firstOrFail();
            $invoiceItems = $invoice->items()->orderBy('id')->lockForUpdate()->get();

            if ($invoiceItems->isEmpty()) {
                throw new \RuntimeException('الفاتورة لا تحتوي على أصناف قابلة للإرجاع');
            }

            $priorReturns = DB::table('sales_invoice_return_items as return_items')
                ->join('sales_invoice_returns as returns', 'returns.id', '=', 'return_items.sales_invoice_return_id')
                ->where('returns.sales_invoice_id', $invoice->id)
                ->whereNull('returns.deleted_at')
                ->select([
                    'return_items.sales_invoice_item_id',
                    'return_items.product_id',
                    'return_items.product_unit_id',
                    'return_items.color_id',
                    'return_items.quantity',
                ])
                ->get();

            $remainingByLine = [];
            foreach ($invoiceItems as $invoiceItem) {
                $alreadyReturned = (int) $priorReturns
                    ->where('sales_invoice_item_id', $invoiceItem->id)
                    ->sum('quantity');
                $remainingByLine[$invoiceItem->id] = max(0, (int) $invoiceItem->quantity - $alreadyReturned);
            }

            $legacyReturns = $priorReturns->whereNull('sales_invoice_item_id');
            foreach ($legacyReturns as $priorReturn) {
                $matchingLines = $invoiceItems->filter(function ($invoiceItem) use ($priorReturn) {
                    if ((int) $invoiceItem->product_id !== (int) $priorReturn->product_id) {
                        return false;
                    }

                    if ($priorReturn->product_unit_id !== null
                        && (int) $invoiceItem->product_unit_id !== (int) $priorReturn->product_unit_id) {
                        return false;
                    }

                    return $priorReturn->color_id === null
                        || (int) $invoiceItem->color_id === (int) $priorReturn->color_id;
                });

                $quantityToAllocate = (int) $priorReturn->quantity;
                foreach ($matchingLines as $invoiceItem) {
                    if ($quantityToAllocate <= 0) {
                        break;
                    }

                    $allocated = min($quantityToAllocate, $remainingByLine[$invoiceItem->id]);
                    $remainingByLine[$invoiceItem->id] -= $allocated;
                    $quantityToAllocate -= $allocated;
                }
            }

            $normalizedItems = [];
            foreach ($request->items as $item) {
                $invoiceItem = null;
                if (!empty($item['sales_invoice_item_id'])) {
                    $invoiceItem = $invoiceItems->firstWhere('id', (int) $item['sales_invoice_item_id']);
                } else {
                    $invoiceItem = $invoiceItems->first(function ($invoiceItem) use ($item, $remainingByLine) {
                        return (int) $invoiceItem->product_id === (int) $item['product_id']
                            && (int) ($invoiceItem->product_unit_id ?? 0) === (int) ($item['product_unit_id'] ?? 0)
                            && (int) ($invoiceItem->color_id ?? 0) === (int) ($item['color_id'] ?? 0)
                            && ($remainingByLine[$invoiceItem->id] ?? 0) >= (int) $item['quantity'];
                    });
                }

                if (!$invoiceItem
                    || (int) $invoiceItem->product_id !== (int) $item['product_id']
                    || (isset($item['product_unit_id']) && (int) $invoiceItem->product_unit_id !== (int) $item['product_unit_id'])
                    || (isset($item['color_id']) && (int) $invoiceItem->color_id !== (int) $item['color_id'])) {
                    throw new \RuntimeException('الصنف المحدد لا يطابق أصناف الفاتورة');
                }

                $quantity = (int) $item['quantity'];
                if ($quantity > ($remainingByLine[$invoiceItem->id] ?? 0)) {
                    throw new \RuntimeException("كمية المرتجع تتجاوز الكمية المتبقية من المنتج '{$invoiceItem->product?->name}'");
                }

                $remainingByLine[$invoiceItem->id] -= $quantity;
                $normalizedItems[] = [
                    'invoice_item' => $invoiceItem,
                    'product_id' => $invoiceItem->product_id,
                    'product_unit_id' => $invoiceItem->product_unit_id,
                    'color_id' => $invoiceItem->color_id,
                    'quantity' => $quantity,
                    'price' => (float) $invoiceItem->price,
                    'reason' => $item['reason'] ?? null,
                ];
            }

            // ✅ تحديد treasury_id (من الطلب أو من الفاتورة الأصلية)
            $treasuryId = $request->treasury_id ?? $invoice->treasury_id;

            if (!$treasuryId) {
                DB::rollBack();
                return response()->json([
                    'result' => 'Error',
                    'message' => 'لا توجد خزينة للتعامل معها (يرجى تحديد خزينة أو التأكد من وجود خزينة في الفاتورة الأصلية)',
                    'status' => 400,
                ], 400);
            }

            $returnNumber = 'SR-' . now()->format('Ymd') . '-' . rand(1000, 9999);

            $totalReturn = collect($normalizedItems)->sum(function($item) {
                return $item['quantity'] * $item['price'];
            });

            // ✅ حساب المبالغ حسب طريقة الدفع
            $cashReturn = 0;
            $cardReturn = 0;
            $walletReturn = 0;

            $returnMethod = $request->return_method;
            
            if (in_array($returnMethod, ['cash', 'نقدي', 'نقداً', 'نقدا'])) {
                $cashReturn = $totalReturn;
            } elseif (in_array($returnMethod, ['card', 'بطاقة', 'بطاقه'])) {
                $cardReturn = $totalReturn;
            } elseif (in_array($returnMethod, ['wallet', 'محفظة', 'محفظه', 'رصيد'])) {
                $walletReturn = $totalReturn;
            }

            $treasury = null;
            if ($cashReturn > 0) {
                $treasury = Treasury::whereKey($treasuryId)->lockForUpdate()->firstOrFail();
                $currency = Currency::findOrFail($invoice->currency_id);
                if ($treasury->getBalanceByCurrency($invoice->currency_id, $currency->code) < $cashReturn) {
                    throw new \RuntimeException('رصيد الخزنة غير كافٍ بهذه العملة');
                }
            }

            // ✅ إنشاء المرتجع مع treasury_id
            $return = SalesInvoiceReturn::create([
                'sales_invoice_id' => $invoice->id,
                'return_number' => $returnNumber,
                'return_method' => $request->return_method,
                'total_amount' => $totalReturn,
                'note' => $request->note,
                'treasury_id' => $treasuryId,
            ]);

            // ✅ 1️⃣ زيادة المخزون
            foreach ($normalizedItems as $item) {
                SalesInvoiceReturnItem::create([
                    'sales_invoice_return_id' => $return->id,
                    'sales_invoice_item_id' => $item['invoice_item']->id,
                    'product_id' => $item['product_id'],
                    'product_unit_id' => $item['product_unit_id'],
                    'color_id' => $item['color_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'total' => $item['quantity'] * $item['price'],
                    'reason' => $item['reason'],
                ]);

                $product = Product::whereKey($item['product_id'])->lockForUpdate()->firstOrFail();
                if ($item['color_id'] !== null) {
                    $productUnit = DB::table('product_units')
                        ->where('product_id', $product->id)
                        ->where('unit_id', $item['product_unit_id'])
                        ->first();
                    if (!$productUnit) {
                        throw new \RuntimeException("الوحدة المحددة غير مرتبطة بالمنتج '{$product->name}'");
                    }

                    $colorStock = DB::table('product_unit_colors')
                        ->where('product_unit_id', $productUnit->id)
                        ->where('color_id', $item['color_id'])
                        ->whereNull('deleted_at')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();
                    if (!$colorStock) {
                        throw new \RuntimeException("اللون المحدد غير مرتبط بالمنتج '{$product->name}'");
                    }

                    DB::table('product_unit_colors')
                        ->where('id', $colorStock->id)
                        ->increment('stock', $item['quantity']);

                    $variantStock = DB::table('product_unit_colors as puc')
                        ->join('product_units as pu', 'pu.id', '=', 'puc.product_unit_id')
                        ->where('pu.product_id', $product->id)
                        ->whereNull('puc.deleted_at')
                        ->sum('puc.stock');
                    $product->update(['stock' => $variantStock]);
                } else {
                    $product->increment('stock', $item['quantity']);
                }

                $warehouseStockUpdated = DB::table('product_warehouse')
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $invoice->warehouse_id)
                    ->increment('stock', $item['quantity']);
                if ($warehouseStockUpdated === 0) {
                    DB::table('product_warehouse')->insertOrIgnore([
                        'product_id' => $product->id,
                        'warehouse_id' => $invoice->warehouse_id,
                        'stock' => $item['quantity'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // ✅ 2️⃣ نقصان الخزينة
            if ($cashReturn > 0 && $treasuryId) {
                $currency = Currency::findOrFail($invoice->currency_id);
                $oldBalance = $treasury->getBalanceByCurrency($invoice->currency_id, $currency->code);
                $treasury->updateBalance($invoice->currency_id, -$cashReturn, $currency->code);

                TreasuryTransaction::create([
                    'treasury_id' => $treasuryId,
                    'reference_type' => SalesInvoiceReturn::class,
                    'reference_id' => $return->id,
                    'type' => 'out',
                    'amount' => $cashReturn,
                    'description' => "مرتجع مبيعات رقم {$return->return_number} من الفاتورة {$invoice->invoice_number}",
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                ]);

                Log::info('Treasury balance decreased for sales return', [
                    'treasury_id' => $treasury->id,
                    'old_balance' => $oldBalance,
                    'amount' => $cashReturn,
                    'return_id' => $return->id,
                ]);
            }

            // ✅ 3️⃣ نقصان نقاط الولاء
            if ($invoice->customer_id) {
                $this->deductLoyaltyPoints($invoice->customer_id, $totalReturn);
            }

            // ✅ 4️⃣ نقصان إجمالي المشتريات
            if ($invoice->customer_id) {
                $this->deductTotalPurchases($invoice->customer_id, $totalReturn);
            }

            // ✅ 5️⃣ تحديث آخر مبلغ مدفوع
            if ($invoice->customer_id) {
                $this->updateLastPaidAmount($invoice->customer_id, $totalReturn);
            }

            DB::commit();

            return response()->json([
                'data' => $return->load('items.product', 'items.unit', 'items.color', 'invoice.customer', 'treasury'),
                'result' => 'Success',
                'message' => 'Sales return created successfully',
                'status' => 200,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('❌ Store Return Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'result' => 'Error',
                'message' => $e->getMessage(),
                'status' => 500,
            ], $e instanceof \RuntimeException ? 400 : 500);
        }
    }

    // ============================================================
    // ✅ دالة خصم نقاط الولاء عند المرتجع
    // ============================================================
    private function deductLoyaltyPoints($customerId, $returnAmount)
    {
        Log::info('⭐ ========== LOYALTY POINTS DEDUCT START ==========');
        Log::info('📊 Customer ID: ' . $customerId);
        Log::info('💰 Return Amount: ' . $returnAmount);

        try {
            $loyaltySetting = LoyaltySetting::first();
            
            if (!$loyaltySetting || $loyaltySetting->point_value <= 0) {
                Log::warning('⚠️ Loyalty settings not found or point_value = 0');
                return;
            }

            $customer = Customer::find($customerId);
            
            if (!$customer) {
                Log::error('❌ Customer not found for ID: ' . $customerId);
                return;
            }

            $currentPoints = $customer->point ?? 0;
            $deductedPoints = floor($returnAmount * $loyaltySetting->point_value);
            $newPoints = max(0, $currentPoints - $deductedPoints);

            Log::info('🧮 Points Deduction:', [
                'current_points' => $currentPoints,
                'deducted_points' => $deductedPoints,
                'new_points' => $newPoints
            ]);

            $customer->point = $newPoints;
            $customer->save();

            Log::info('✅ Points deducted!', [
                'customer_id' => $customer->id,
                'new_points' => $customer->point
            ]);

            $this->updateCustomerLevel($customer, $loyaltySetting);

        } catch (\Exception $e) {
            Log::error('❌ Error deducting loyalty points: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }

        Log::info('⭐ ========== LOYALTY POINTS DEDUCT END ==========');
    }

    // ============================================================
    // ✅ دالة خصم إجمالي المشتريات عند المرتجع
    // ============================================================
    private function deductTotalPurchases($customerId, $returnAmount)
    {
        Log::info('📊 ========== TOTAL PURCHASES DEDUCT START ==========');
        Log::info('📊 Customer ID: ' . $customerId);
        Log::info('💰 Return Amount: ' . $returnAmount);

        try {
            $customer = Customer::find($customerId);
            
            if (!$customer) {
                Log::error('❌ Customer not found for ID: ' . $customerId);
                return;
            }

            $oldTotalPurchases = $customer->total_purchases ?? 0;
            $newTotalPurchases = max(0, $oldTotalPurchases - $returnAmount);

            $customer->total_purchases = $newTotalPurchases;
            $customer->save();

            Log::info('✅ Total purchases deducted!', [
                'customer_id' => $customer->id,
                'old_total_purchases' => $oldTotalPurchases,
                'new_total_purchases' => $customer->total_purchases
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Error deducting total purchases: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }

        Log::info('📊 ========== TOTAL PURCHASES DEDUCT END ==========');
    }

    // ============================================================
    // ✅ دالة تحديث آخر مبلغ مدفوع
    // ============================================================
    private function updateLastPaidAmount($customerId, $returnAmount)
    {
        Log::info('💳 ========== LAST PAID AMOUNT UPDATE START ==========');
        Log::info('📊 Customer ID: ' . $customerId);
        Log::info('💰 Return Amount: ' . $returnAmount);

        try {
            $customer = Customer::find($customerId);
            
            if (!$customer) {
                Log::error('❌ Customer not found for ID: ' . $customerId);
                return;
            }

            $oldLastPaidAmount = $customer->last_paid_amount ?? 0;
            $newLastPaidAmount = max(0, $oldLastPaidAmount - $returnAmount);

            $customer->last_paid_amount = $newLastPaidAmount;
            $customer->save();

            Log::info('✅ Last paid amount updated!', [
                'customer_id' => $customer->id,
                'old_last_paid_amount' => $oldLastPaidAmount,
                'new_last_paid_amount' => $customer->last_paid_amount
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Error updating last paid amount: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }

        Log::info('💳 ========== LAST PAID AMOUNT UPDATE END ==========');
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
}