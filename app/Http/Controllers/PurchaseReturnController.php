<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReturnRequest;
use App\Http\Resources\PurchaseReturnResource;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Transfer;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseReturnController extends Controller
{
    public function index(Request $request)
    {
        try {
            $filters = $request->input('filters', []);
            $orderBy = $request->input('orderBy', 'id');
            $orderByDirection = $request->input('orderByDirection', 'desc');
            $perPage = $request->input('perPage', 10);
            $paginate = $request->boolean('paginate', true);

            $query = PurchaseReturn::with([
                'purchaseInvoice',
                'items.product',
                'items.color',
                'items.unit',
                'treasury',
                'currency',
                'warehouse',
            ]);

            // filters
            if (!empty($filters['return_number'])) {
                $query->where('return_number', 'like', '%' . $filters['return_number'] . '%');
            }

            if (!empty($filters['invoice_number'])) {
                $query->whereHas('purchaseInvoice', function ($q) use ($filters) {
                    $q->where('invoice_number', 'like', '%' . $filters['invoice_number'] . '%');
                });
            }

            if (!empty($filters['purchase_invoices_id'])) {
                $query->where('purchase_invoices_id', $filters['purchase_invoices_id']);
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

            if (!empty($filters['reason'])) {
                $query->where('reason', 'like', '%' . $filters['reason'] . '%');
            }

            if (!empty($filters['treasury_id'])) {
                $query->where('treasury_id', $filters['treasury_id']);
            }

            if (!empty($filters['currency_id'])) {
                $query->where('currency_id', $filters['currency_id']);
            }

            if (!empty($filters['warehouse_id'])) {
                $query->where('warehouse_id', $filters['warehouse_id']);
            }

            if (!empty($filters['payment_method'])) {
                $query->where('payment_method', $filters['payment_method']);
            }

            if (!empty($filters['return_date_from'])) {
                $query->whereDate('return_date', '>=', $filters['return_date_from']);
            }

            if (!empty($filters['return_date_to'])) {
                $query->whereDate('return_date', '<=', $filters['return_date_to']);
            }

            $query->orderBy($orderBy, $orderByDirection);

            if ($paginate) {
                $returns = $query->paginate($perPage);

                return response()->json([
                    'data' => PurchaseReturnResource::collection($returns->items()),
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
                    'message' => 'Purchase returns fetched successfully',
                    'status' => 200,
                ]);
            }

            $returns = $query->get();

            return response()->json([
                'data' => PurchaseReturnResource::collection($returns),
                'links' => null,
                'meta' => null,
                'result' => 'Success',
                'message' => 'Purchase returns fetched successfully',
                'status' => 200,
            ]);

        } catch (\Exception $e) {
            Log::error('Purchase returns fetch failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'result' => 'Error',
                'message' => $e->getMessage(),
                'status' => 500,
            ], 500);
        }
    }

    public function store(StoreReturnRequest $request)
    {
        DB::beginTransaction();

        try {
            // Serialize returns against the same invoice so two concurrent requests
            // cannot both pass the remaining-quantity check.
            $invoice = PurchaseInvoice::whereKey($request->purchase_invoices_id)
                ->lockForUpdate()
                ->first();

            if (!$invoice) {
                throw new \RuntimeException('Invoice not found');
            }

            $invoiceItems = $invoice->items()->lockForUpdate()->get();
            $lines = collect($request->items)->map(function ($item) {
                $item['product_unit_id'] = $item['product_unit_id'] ?? null;
                $item['color_id'] = $item['color_id'] ?? null;
                return $item;
            });

            // Group duplicate request lines before validating to prevent bypassing
            // the remaining quantity by splitting one color into multiple rows.
            $groupedLines = $lines->groupBy(fn ($item) => implode(':', [
                $item['product_id'], $item['product_unit_id'] ?? 'null', $item['color_id'] ?? 'null',
            ]));
            $normalizedLines = [];

            foreach ($groupedLines as $group) {
                $item = $group->first();
                $item['quantity'] = $group->sum(fn ($line) => (int) $line['quantity']);

                $invoiceLineItems = $invoiceItems->filter(fn ($invoiceItem) =>
                    (int) $invoiceItem->product_id === (int) $item['product_id']
                    && (string) ($invoiceItem->product_unit_id ?? '') === (string) ($item['product_unit_id'] ?? '')
                    && (string) ($invoiceItem->color_id ?? '') === (string) ($item['color_id'] ?? '')
                );

                if ($invoiceLineItems->isEmpty()) {
                    throw new \RuntimeException('المنتج/الوحدة/اللون غير موجودين في الفاتورة الأصلية');
                }

                $purchasedQuantity = (int) $invoiceLineItems->sum('quantity');
                $alreadyReturned = PurchaseReturnItem::whereHas('purchaseReturn', function ($query) use ($invoice) {
                    $query->where('purchase_invoices_id', $invoice->id);
                })
                    ->where('product_id', $item['product_id'])
                    ->where('product_unit_id', $item['product_unit_id'])
                    ->where('color_id', $item['color_id'])
                    ->sum('quantity');

                $availableQuantity = $purchasedQuantity - (int) $alreadyReturned;
                if ($item['quantity'] > $availableQuantity) {
                    throw new \RuntimeException('الكمية المرتجعة (' . $item['quantity'] . ') أكبر من الكمية المتبقية (' . max(0, $availableQuantity) . ') لنفس اللون والوحدة');
                }

                $normalizedLines[] = $item;
            }

            $total = collect($normalizedLines)->sum(fn ($item) => $item['quantity'] * $item['unit_price']);
            // Apply the return against the supplier payable first, including on
            // unpaid/partially-paid invoices. Refund only any excess over the debt.
            $outstandingBeforeReturn = max(0, (float) $invoice->remaining_amount);
            $cashRefundAmount = $invoice->payment_method === 'cash' && $invoice->treasury_id
                ? min(
                    max(0, (float) $total - $outstandingBeforeReturn),
                    max(0, (float) $invoice->paid_amount)
                )
                : 0;

            $return = PurchaseReturn::create([
                'purchase_invoices_id' => $invoice->id,
                'return_number' => 'PR-' . now()->format('Ymd') . '-' . rand(1000, 9999),
                'total_amount' => $total,
                'paid_amount' => $cashRefundAmount,
                'payment_method' => $cashRefundAmount > 0 ? 'cash' : 'credit',
                'return_date' => $request->return_date ?? now()->format('Y-m-d'),
                'reason' => $request->reason,
                'treasury_id' => $invoice->treasury_id,
                'currency_id' => $invoice->currency_id,
                'warehouse_id' => $invoice->warehouse_id,
            ]);

            foreach ($normalizedLines as $item) {
                $quantity = (int) $item['quantity'];
                $lineTotal = $quantity * $item['unit_price'];

                PurchaseReturnItem::create([
                    'purchase_return_id' => $return->id,
                    'product_id' => $item['product_id'],
                    'product_unit_id' => $item['product_unit_id'],
                    'color_id' => $item['color_id'],
                    'quantity' => $quantity,
                    'unit_price' => $item['unit_price'],
                    'total_price' => $lineTotal,
                ]);

                $product = Product::whereKey($item['product_id'])->lockForUpdate()->first();
                if ($product && ($item['product_unit_id'] === null || $item['color_id'] === null)) {
                    if ((int) $product->stock < $quantity) {
                        throw new \RuntimeException("المخزون الإجمالي للمنتج '{$product->name}' لا يكفي لإرجاع الكمية للمورد");
                    }
                    $product->decrement('stock', $quantity);
                }

                if ($item['product_unit_id'] !== null && $item['color_id'] !== null) {
                    $productUnit = DB::table('product_units')
                        ->where('product_id', $item['product_id'])
                        ->where('unit_id', $item['product_unit_id'])
                        ->first();

                    $colorStocks = $productUnit ? DB::table('product_unit_colors')
                        ->where('product_unit_id', $productUnit->id)
                        ->where('color_id', $item['color_id'])
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->orderBy('id')
                        ->get() : collect();

                    $availableColorStock = (int) $colorStocks->sum('stock');
                    if ($colorStocks->isEmpty() || $availableColorStock < $quantity) {
                        throw new \RuntimeException('رصيد اللون المحدد غير كافٍ لإتمام مرتجع الشراء');
                    }

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

                    if ($product) {
                        $variantTotal = DB::table('product_unit_colors as puc')
                            ->join('product_units as pu', 'pu.id', '=', 'puc.product_unit_id')
                            ->where('pu.product_id', $product->id)
                            ->whereNull('puc.deleted_at')
                            ->sum('puc.stock');
                        $product->update(['stock' => $variantTotal]);
                    }
                }

                DB::table('product_warehouse')
                    ->where('product_id', $item['product_id'])
                    ->where('warehouse_id', $invoice->warehouse_id)
                    ->decrement('stock', $quantity);
            }

            if ($cashRefundAmount > 0) {
                $treasury = Treasury::lockForUpdate()->find($invoice->treasury_id);
                if (!$treasury) {
                    throw new \RuntimeException('الخزنة غير موجودة');
                }

                $treasury->increment('balance', $cashRefundAmount);

                Transfer::create([
                    'type' => 'treasury_deposit',
                    'treasury_id' => $invoice->treasury_id,
                    'purchase_invoice_id' => $invoice->id,
                    'amount' => $cashRefundAmount,
                    'notes' => "مرتجع لفاتورة مشتريات رقم {$invoice->invoice_number}",
                ]);

                TreasuryTransaction::create([
                    'treasury_id' => $invoice->treasury_id,
                    'reference_type' => PurchaseReturn::class,
                    'reference_id' => $return->id,
                    'type' => 'in',
                    'amount' => $cashRefundAmount,
                    'description' => "مرتجع فاتورة مشتريات رقم {$invoice->invoice_number}",
                    'created_at' => now(),
                ]);

                $invoice->decrement('paid_amount', $cashRefundAmount);
            }

            DB::commit();

            $return->load([
                'items.product', 'items.color', 'items.unit', 'purchaseInvoice',
                'treasury', 'currency', 'warehouse',
            ]);

            return response()->json([
                'data' => new PurchaseReturnResource($return),
                'result' => 'Success',
                'message' => 'Purchase return created successfully',
                'status' => 200,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Purchase return creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            $status = $e instanceof \RuntimeException ? 422 : 500;
            return response()->json([
                'result' => 'Error',
                'message' => $e instanceof \RuntimeException ? $e->getMessage() : 'Failed to create purchase return',
                'error' => config('app.debug') && !$e instanceof \RuntimeException ? $e->getMessage() : null,
                'status' => $status,
            ], $status);
        }
    }

    public function show($id)
    {
        try {
            $return = PurchaseReturn::with([
                'items.product',
                'items.color',
                'items.unit',
                'purchaseInvoice',
                'purchaseInvoice.items',
                'treasury',
                'currency',
                'warehouse',
                'treasuryTransactions'
            ])->findOrFail($id);

            return response()->json([
                'data' => new PurchaseReturnResource($return),
                'result' => 'Success',
                'message' => 'Purchase return fetched successfully',
                'status' => 200,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'result' => 'Error',
                'message' => 'Purchase return not found',
                'status' => 404,
            ], 404);

        } catch (\Exception $e) {
            Log::error('Purchase return fetch failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'id' => $id
            ]);

            return response()->json([
                'result' => 'Error',
                'message' => $e->getMessage(),
                'status' => 500,
            ], 500);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $return = PurchaseReturn::with('items')->findOrFail($id);
            $invoice = PurchaseInvoice::find($return->purchase_invoices_id);

            foreach ($return->items as $item) {
                $product = Product::find($item->product_id);
                if ($product) {
                    $product->increment('stock', $item->quantity);
                }

                if ($item->product_unit_id && $item->color_id) {
                    $productUnit = DB::table('product_units')
                        ->where('product_id', $item->product_id)
                        ->where('unit_id', $item->product_unit_id)
                        ->first();

                    if ($productUnit) {
                        DB::table('product_unit_colors')
                            ->where('product_unit_id', $productUnit->id)
                            ->where('color_id', $item->color_id)
                            ->increment('stock', $item->quantity);
                    }
                }

                if ($invoice) {
                    DB::table('product_warehouse')
                        ->where('product_id', $item->product_id)
                        ->where('warehouse_id', $invoice->warehouse_id)
                        ->increment('stock', $item->quantity);
                }
            }

            TreasuryTransaction::where('reference_type', PurchaseReturn::class)
                ->where('reference_id', $return->id)
                ->delete();

            $return->delete();

            DB::commit();

            return response()->json([
                'result' => 'Success',
                'message' => 'Purchase return deleted successfully',
                'status' => 200,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Purchase return deletion failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'id' => $id
            ]);

            return response()->json([
                'result' => 'Error',
                'message' => 'Failed to delete purchase return',
                'error' => config('app.debug') ? $e->getMessage() : null,
                'status' => 500,
            ], 500);
        }
    }
}
