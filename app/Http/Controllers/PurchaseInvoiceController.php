<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseInvoiceRequest;
use App\Http\Resources\PurchaseInvoiceResource;
use App\Models\Product;
use App\Models\Currency;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Tax;
use App\Models\Transfer;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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

    private function recordInvoicePayment(
        PurchaseInvoice $invoice,
        int $treasuryId,
        int $currencyId,
        float $amount,
        string $description
    ): void {
        if ($amount <= 0) {
            return;
        }

        $currency = Currency::findOrFail($currencyId);
        $treasury = Treasury::whereKey($treasuryId)->lockForUpdate()->firstOrFail();
        $balance = $treasury->getBalanceByCurrency($currencyId, $currency->code);

        if ($balance < $amount) {
            throw ValidationException::withMessages([
                'paid_amount' => ['رصيد الخزنة غير كافٍ بهذه العملة'],
            ]);
        }

        $treasury->updateBalance($currencyId, -$amount, $currency->code);

        TreasuryTransaction::create([
            'treasury_id' => $treasury->id,
            'reference_type' => PurchaseInvoice::class,
            'reference_id' => $invoice->id,
            'type' => 'out',
            'amount' => $amount,
            'description' => $description,
        ]);

        Transfer::create([
            'type' => 'treasury_withdraw',
            'from_treasury_id' => $treasury->id,
            'purchase_invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency' => $currency->code,
            'notes' => $description,
            'created_by' => auth()->id(),
        ]);
    }

    private function resolvePurchaseProductUnit(int $productId, ?int $unitId, ?int $colorId)
    {
        $hasColorVariants = DB::table('product_unit_colors as puc')
            ->join('product_units as pu', 'pu.id', '=', 'puc.product_unit_id')
            ->where('pu.product_id', $productId)
            ->whereNull('puc.deleted_at')
            ->exists();

        if ($hasColorVariants && (!$unitId || !$colorId)) {
            throw new \RuntimeException('يجب تحديد الوحدة واللون للمنتج ذي المتغيرات');
        }

        if ($colorId === null) {
            return null;
        }

        $productUnit = DB::table('product_units')
            ->where('product_id', $productId)
            ->where('unit_id', $unitId)
            ->first();

        if (!$productUnit) {
            throw new \RuntimeException('الوحدة المحددة غير مرتبطة بالمنتج');
        }

        $colorExists = DB::table('product_unit_colors')
            ->where('product_unit_id', $productUnit->id)
            ->where('color_id', $colorId)
            ->whereNull('deleted_at')
            ->exists();

        if (!$colorExists) {
            throw new \RuntimeException('اللون المحدد غير مرتبط بالوحدة والمنتج');
        }

        return $productUnit;
    }

    private function reconcileInvoiceInventory(
        $currentItems,
        array $requestedItems,
        int $currentWarehouseId,
        int $requestedWarehouseId
    ): void {
        $stockDeltas = [];
        $warehouseDeltas = [];

        foreach ($requestedItems as $item) {
            $this->resolvePurchaseProductUnit(
                (int) $item['product_id'],
                isset($item['unit_id']) ? (int) $item['unit_id'] : null,
                isset($item['color_id']) ? (int) $item['color_id'] : null
            );
        }

        foreach ($currentItems as $item) {
            $key = implode(':', [
                $item->product_id,
                $item->product_unit_id ?? '',
                $item->color_id ?? '',
            ]);

            if (!isset($stockDeltas[$key])) {
                $stockDeltas[$key] = [
                    'product_id' => (int) $item->product_id,
                    'unit_id' => $item->product_unit_id ? (int) $item->product_unit_id : null,
                    'color_id' => $item->color_id ? (int) $item->color_id : null,
                    'quantity' => 0,
                ];
            }
            $stockDeltas[$key]['quantity'] -= (int) $item->quantity;

            $warehouseKey = $item->product_id . ':' . $currentWarehouseId;
            $warehouseDeltas[$warehouseKey] = ($warehouseDeltas[$warehouseKey] ?? 0) - (int) $item->quantity;
        }

        foreach ($requestedItems as $item) {
            $unitId = $item['unit_id'] ?? null;
            $colorId = $item['color_id'] ?? null;
            $key = implode(':', [$item['product_id'], $unitId ?? '', $colorId ?? '']);

            if (!isset($stockDeltas[$key])) {
                $stockDeltas[$key] = [
                    'product_id' => (int) $item['product_id'],
                    'unit_id' => $unitId ? (int) $unitId : null,
                    'color_id' => $colorId ? (int) $colorId : null,
                    'quantity' => 0,
                ];
            }
            $stockDeltas[$key]['quantity'] += (int) $item['quantity'];

            $warehouseKey = $item['product_id'] . ':' . $requestedWarehouseId;
            $warehouseDeltas[$warehouseKey] = ($warehouseDeltas[$warehouseKey] ?? 0) + (int) $item['quantity'];
        }

        foreach ($stockDeltas as $delta) {
            if ($delta['quantity'] === 0) {
                continue;
            }

            $product = Product::whereKey($delta['product_id'])->lockForUpdate()->firstOrFail();

            if ($delta['color_id'] !== null) {
                $productUnit = DB::table('product_units')
                    ->where('product_id', $product->id)
                    ->where('unit_id', $delta['unit_id'])
                    ->first();

                if (!$productUnit) {
                    throw new \RuntimeException('A matching product unit is required for items with color variants');
                }

                $colorStock = DB::table('product_unit_colors')
                    ->where('product_unit_id', $productUnit->id)
                    ->where('color_id', $delta['color_id'])
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->orderBy('id')
                    ->first();

                if (!$colorStock && $delta['quantity'] < 0) {
                    throw new \RuntimeException('The existing color stock record for this purchase item was not found');
                }

                if ($delta['quantity'] < 0 && (int) $colorStock->stock < abs($delta['quantity'])) {
                    throw new \RuntimeException('لا يمكن تقليل كمية الفاتورة لأن جزءاً من رصيد هذا اللون تم بيعه أو استخدامه');
                }

                if ($colorStock) {
                    $query = DB::table('product_unit_colors')->where('id', $colorStock->id);
                    if ($delta['quantity'] > 0) {
                        $query->increment('stock', $delta['quantity']);
                    } else {
                        $query->decrement('stock', abs($delta['quantity']));
                    }
                } else {
                    $now = now();
                    DB::table('product_unit_colors')->insert([
                        'product_unit_id' => $productUnit->id,
                        'color_id' => $delta['color_id'],
                        'stock' => $delta['quantity'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $variantTotal = DB::table('product_unit_colors as puc')
                    ->join('product_units as pu', 'pu.id', '=', 'puc.product_unit_id')
                    ->where('pu.product_id', $product->id)
                    ->whereNull('puc.deleted_at')
                    ->sum('puc.stock');
                $product->update(['stock' => $variantTotal]);
            } else {
                if ($delta['quantity'] > 0) {
                    $product->increment('stock', $delta['quantity']);
                } else {
                    if ((int) $product->stock < abs($delta['quantity'])) {
                        throw new \RuntimeException('لا يمكن تقليل كمية الفاتورة لأن جزءاً من رصيد المنتج تم بيعه أو استخدامه');
                    }
                    $product->decrement('stock', abs($delta['quantity']));
                }
            }
        }

        foreach ($warehouseDeltas as $key => $quantity) {
            if ($quantity === 0) {
                continue;
            }

            [$productId, $warehouseId] = array_map('intval', explode(':', $key));
            $warehouseStock = DB::table('product_warehouse')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($warehouseStock) {
                if ($quantity < 0 && (int) $warehouseStock->stock < abs($quantity)) {
                    throw new \RuntimeException('لا يمكن تقليل كمية الفاتورة لأن رصيد المستودع تم استخدامه');
                }

                $query = DB::table('product_warehouse')
                    ->where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId);
                if ($quantity > 0) {
                    $query->increment('stock', $quantity);
                } else {
                    $query->decrement('stock', abs($quantity));
                }
            } elseif ($quantity > 0) {
                $now = now();
                DB::table('product_warehouse')->insert([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'stock' => $quantity,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                throw new \RuntimeException('The existing warehouse stock record for this purchase item was not found');
            }
        }
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
    
                $productUnit = $this->resolvePurchaseProductUnit(
                    (int) $item['product_id'],
                    isset($item['unit_id']) ? (int) $item['unit_id'] : null,
                    isset($item['color_id']) ? (int) $item['color_id'] : null
                );
    
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
                    $now = now();
                    DB::table('product_warehouse')->insert([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $request->warehouse_id,
                        'stock' => $item['quantity'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }        
            
            /*
            =============================
            ✅ الخزنة
            =============================
            */
            if ((float) $request->paid_amount > 0) {
                $this->recordInvoicePayment(
                    $invoice,
                    (int) $request->treasury_id,
                    (int) $request->currency_id,
                    (float) $request->paid_amount,
                    "دفعة لفاتورة مشتريات رقم {$invoice->invoice_number}"
                );
            }

            $invoice->syncPaidExpense();

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
                        'treasuryTransactions.treasury',
                        'items.product',
                        'items.unit',
                        'items.color'
                    )
                ),
                'result' => 'Success',
                'message' => 'Purchase invoice created successfully',
                'status' => 200,
            ]);
    
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
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
                'treasuryTransactions.treasury',
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
                'treasuryTransactions.treasury',
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

    public function payables()
    {
        try {
            $returnTotals = DB::table('purchase_returns')
                ->select('purchase_invoices_id')
                ->selectRaw('SUM(total_amount) as total_returns')
                ->whereNull('deleted_at')
                ->groupBy('purchase_invoices_id');

            $totalPayables = PurchaseInvoice::query()
                ->leftJoinSub($returnTotals, 'return_totals', function ($join) {
                    $join->on('purchase_invoices.id', '=', 'return_totals.purchase_invoices_id');
                })
                ->selectRaw(
                    'COALESCE(SUM(GREATEST(purchase_invoices.total_amount - purchase_invoices.paid_amount - COALESCE(return_totals.total_returns, 0), 0)), 0) as total_payables'
                )
                ->value('total_payables');

            return response()->json([
                'data' => ['total_payables' => (float) $totalPayables],
                'result' => 'Success',
                'message' => 'Supplier payables fetched successfully',
                'status' => 200,
            ]);
        } catch (\Exception $e) {
            Log::error('Supplier payables fetch failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'result' => 'Error',
                'message' => 'Failed to fetch supplier payables',
                'status' => 500,
            ], 500);
        }
    }
    
    
    // ========== pay ==========
    public function pay(Request $request, PurchaseInvoice $invoice)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash',
            'treasury_id' => 'required|exists:treasuries,id',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'payment_date' => 'nullable|date',
        ]);

        try {
            $updatedInvoice = DB::transaction(function () use ($data, $invoice) {
                $lockedInvoice = PurchaseInvoice::whereKey($invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $amount = (float) $data['amount'];
                $remainingAmount = (float) $lockedInvoice->remaining_amount;

                if ($amount > $remainingAmount) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'amount' => ['Amount exceeds the remaining invoice balance'],
                    ]);
                }

                $treasury = Treasury::whereKey($data['treasury_id'])
                    ->lockForUpdate()
                    ->firstOrFail();
                $currencyId = $lockedInvoice->currency_id;
                $currencyCode = Currency::findOrFail($currencyId)->code;
                $treasuryBalance = $treasury->getBalanceByCurrency($currencyId, $currencyCode);

                if ($treasuryBalance < $amount) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'treasury_id' => ['رصيد الخزنة غير كافٍ بهذه العملة'],
                    ]);
                }

                $treasury->updateBalance($currencyId, -$amount, $currencyCode);
                $lockedInvoice->increment('paid_amount', $amount);
                $lockedInvoice->update([
                    'treasury_id' => $treasury->id,
                    'payment_method' => 'cash',
                ]);
                $description = "دفعة نقدية لفاتورة مشتريات رقم {$lockedInvoice->invoice_number}";
                if (!empty($data['reference_number'])) {
                    $description .= " - مرجع: {$data['reference_number']}";
                }
                if (!empty($data['notes'])) {
                    $description .= " - {$data['notes']}";
                }

                $lockedInvoice->syncPaidExpense();

                TreasuryTransaction::create([
                    'treasury_id' => $treasury->id,
                    'reference_type' => PurchaseInvoice::class,
                    'reference_id' => $lockedInvoice->id,
                    'type' => 'out',
                    'amount' => $amount,
                    'description' => $description,
                    'created_at' => $data['payment_date'] ?? now(),
                ]);

                Transfer::create([
                    'type' => 'treasury_withdraw',
                    'from_treasury_id' => $treasury->id,
                    'purchase_invoice_id' => $lockedInvoice->id,
                    'amount' => $amount,
                    'currency' => $currencyCode,
                    'notes' => $description,
                    'created_by' => auth()->id(),
                    'created_at' => $data['payment_date'] ?? now(),
                ]);

                return $lockedInvoice->fresh();
            });

            return response()->json([
                'message' => 'Payment updated successfully',
                'invoice' => $updatedInvoice,
                'remaining' => $updatedInvoice->remaining_amount,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Purchase invoice payment failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to record purchase invoice payment',
            ], 500);
        }
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
            $oldCurrencyId = $invoice->currency_id;

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

            $existingItems = $invoice->items
                ->groupBy(fn ($item) => implode(':', [
                    $item->product_id,
                    $item->product_unit_id ?? '',
                    $item->color_id ?? '',
                ]))
                ->map(fn ($items) => $items->values());

            $this->reconcileInvoiceInventory(
                $invoice->items,
                $request->items,
                (int) $invoice->warehouse_id,
                (int) $request->warehouse_id
            );

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
                $itemKey = implode(':', [
                    $item['product_id'],
                    $item['unit_id'] ?? '',
                    $item['color_id'] ?? '',
                ]);
                $existingItem = $existingItems->get($itemKey)?->shift();
                $itemData = [
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
                ];

                if ($existingItem) {
                    $existingItem->update($itemData);
                } else {
                    PurchaseInvoiceItem::create($itemData);
                }

            }

            foreach ($existingItems as $unusedItems) {
                foreach ($unusedItems as $unusedItem) {
                    $unusedItem->delete();
                }
            }

            // 5️⃣ **معالجة الخزينة**
            $previousTransactions = TreasuryTransaction::where('reference_type', PurchaseInvoice::class)
                ->where('reference_id', $invoice->id)
                ->get();

            if ($previousTransactions->isNotEmpty()) {
                $oldCurrency = $oldCurrencyId
                    ? Currency::find($oldCurrencyId)
                    : null;

                foreach ($previousTransactions as $transaction) {
                    $oldTreasury = Treasury::whereKey($transaction->treasury_id)->lockForUpdate()->first();
                    if ($oldTreasury && $oldCurrency) {
                        $oldTreasury->updateBalance(
                            $oldCurrencyId,
                            (float) $transaction->amount,
                            $oldCurrency->code
                        );
                    } elseif ($oldTreasury) {
                        $oldTreasury->increment('balance', $transaction->amount);
                    }
                }
            } elseif ($oldPaymentMethod === 'cash' && $oldPaidAmount > 0 && $oldTreasuryId) {
                // Restore balances deducted by older invoice saves that did not create treasury transactions.
                $oldTreasury = Treasury::find($oldTreasuryId);
                if ($oldTreasury) {
                    $oldTreasury->increment('balance', $oldPaidAmount);
                }
            }

            TreasuryTransaction::where('reference_type', PurchaseInvoice::class)
                ->where('reference_id', $invoice->id)
                ->delete();
            Transfer::where('purchase_invoice_id', $invoice->id)->delete();

            if ((float) $request->paid_amount > 0) {
                $this->recordInvoicePayment(
                    $invoice,
                    (int) $request->treasury_id,
                    (int) $request->currency_id,
                    (float) $request->paid_amount,
                    "تحديث فاتورة مشتريات رقم {$invoice->invoice_number}"
                );
            }

            $invoice->syncPaidExpense();

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
                        'treasuryTransactions.treasury',
                        'items.product',
                        'items.unit',
                        'items.color'
                    )
                ),
                'result' => 'Success',
                'message' => 'Purchase invoice updated successfully',
                'status' => 200,
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
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
