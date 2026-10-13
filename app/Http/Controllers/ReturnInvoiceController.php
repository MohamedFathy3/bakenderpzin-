<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReturnInvoiceResource;
use App\Models\Admin;
use App\Models\CashierShift;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ReturnInvoice;
use App\Models\ReturnItem;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReturnInvoiceController extends Controller
{
    public function storeReturn(Request $request)
    {
        DB::beginTransaction();

        try {

            $invoice = Invoice::with(['items', 'cashier'])
                ->where('invoice_number', $request->invoice_number)
                ->lockForUpdate()
                ->firstOrFail();

            $total = 0;

            foreach ($request->input('items', []) as $item) {

                $invoiceItem = $this->findInvoiceItem($invoice, $item);

                if (!$invoiceItem) {
                    throw new \RuntimeException("المنتج غير موجود في الفاتورة {$invoice->invoice_number}");
                }

                $alreadyReturned = ReturnItem::where('product_id', $item['product_id'])
                    ->whereHas('returnInvoice', function ($q) use ($invoice) {
                        $q->where('invoice_id', $invoice->id);
                    })
                    ->get()
                    ->filter(function (ReturnItem $returnItem) use ($invoiceItem) {
                        return ($returnItem->color === $invoiceItem->color
                                && $returnItem->size === $invoiceItem->size)
                            || ($returnItem->color === null && $returnItem->size === null);
                    })
                    ->sum('quantity');

                $remaining = $invoiceItem->quantity - $alreadyReturned;

                if ($item['quantity'] > $remaining) {
                    throw new \RuntimeException("الكمية المرتجعة أكبر من المتبقي للمنتج {$invoiceItem->product_name}");
                }

                $total += $invoiceItem->price * $item['quantity'];
            }

            /*
            |--------------------------------------------------------------------------
            | إجمالي المدفوعات
            |--------------------------------------------------------------------------
            */

            $payments = collect($request->input('payments', []));
            $paymentsTotal = (float) $payments->sum('amount');
            $refundMethod = strtolower(trim((string) $request->refund_method));
            $cashRefund = 0;
            if (in_array($refundMethod, ['cash', 'نقدي', 'نقداً', 'نقدا'], true)) {
                $cashPayments = $payments->filter(function ($payment) {
                    return in_array(
                        strtolower(trim((string) ($payment['method'] ?? ''))),
                        ['cash', 'نقدي', 'نقداً', 'نقدا'],
                        true
                    );
                });
                $cashRefund = $payments->isEmpty() ? (float) $total : (float) $cashPayments->sum('amount');
            }
            $refundedAmount = $payments->isEmpty() ? $cashRefund : $paymentsTotal;

            $treasury = null;
            if ($cashRefund > 0) {
                $treasuryId = $invoice->treasury_id
                    ?: $invoice->treasuryTransactions()
                        ->where('type', 'in')
                        ->latest('id')
                        ->value('treasury_id')
                    ?: $invoice->cashier?->treasury_id;
                if (!$treasuryId) {
                    throw new \RuntimeException('لا توجد خزينة مرتبطة بفاتورة البيع لصرف المبلغ النقدي المرتجع');
                }

                $treasury = Treasury::whereKey($treasuryId)->lockForUpdate()->firstOrFail();
                if ((float) $treasury->balance < $cashRefund) {
                    throw new \RuntimeException('رصيد الخزينة غير كافٍ لصرف المبلغ النقدي المرتجع');
                }
            }

            /*
            |--------------------------------------------------------------------------
            | إنشاء المرتجع
            |--------------------------------------------------------------------------
            */

            $return = ReturnInvoice::create([
                'invoice_id'      => $invoice->id,
                'total_amount'    => $total,
                'refunded_amount' => $refundedAmount,
                'refund_method'   => $request->refund_method,
                'reason'          => $request->reason,
            ]);

            /*
            |--------------------------------------------------------------------------
            | حفظ العناصر المرتجعة
            |--------------------------------------------------------------------------
            */

            foreach ($request->input('items', []) as $item) {

                $invoiceItem = $this->findInvoiceItem($invoice, $item);
                if (!$invoiceItem) {
                    throw new \RuntimeException("المنتج غير موجود في الفاتورة {$invoice->invoice_number}");
                }

                $product = Product::whereKey($item['product_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $return->items()->create([
                    'product_id'   => $product->id,
                    'product_name' => $product->name,
                    'color'        => $invoiceItem->color,
                    'size'         => $invoiceItem->size,
                    'quantity'     => $item['quantity'],
                    'price'        => $invoiceItem->price,
                    'total'        => $invoiceItem->price * $item['quantity'],
                ]);

                $unitId = $invoiceItem->unit_id;
                if (!$unitId && $invoiceItem->size) {
                    $unitId = DB::table('units')
                        ->where('name', $invoiceItem->size)
                        ->value('id');
                }

                $colorId = $invoiceItem->color_id;
                if (!$colorId && $invoiceItem->color) {
                    $colorId = DB::table('colors')
                        ->where('name', $invoiceItem->color)
                        ->value('id');
                }

                if ($colorId) {
                    $productUnit = DB::table('product_units')
                        ->where('product_id', $product->id)
                        ->where('unit_id', $unitId)
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->first();

                    if (!$productUnit) {
                        throw new \RuntimeException(
                            "تعذر تحديد مقاس المنتج '{$product->name}' لإرجاع مخزون اللون"
                        );
                    }

                    $colorStock = DB::table('product_unit_colors')
                        ->where('product_unit_id', $productUnit->id)
                        ->where('color_id', $colorId)
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->first();

                    if (!$colorStock) {
                        throw new \RuntimeException(
                            "اللون المحدد غير مرتبط بالمقاس في المنتج '{$product->name}'"
                        );
                    }

                    DB::table('product_unit_colors')
                        ->where('id', $colorStock->id)
                        ->increment('stock', $item['quantity']);

                    $variantStock = DB::table('product_unit_colors as puc')
                        ->join('product_units as pu', 'pu.id', '=', 'puc.product_unit_id')
                        ->where('pu.product_id', $product->id)
                        ->whereNull('pu.deleted_at')
                        ->whereNull('puc.deleted_at')
                        ->sum('puc.stock');

                    $product->update(['stock' => $variantStock]);
                } else {
                    $product->increment('stock', $item['quantity']);
                }
            }

            if ($cashRefund > 0 && $treasury) {
                $treasury->decrement('balance', $cashRefund);

                $authenticatedUser = auth()->user();
                TreasuryTransaction::create([
                    'treasury_id' => $treasury->id,
                    'reference_type' => ReturnInvoice::class,
                    'reference_id' => $return->id,
                    'type' => 'out',
                    'amount' => $cashRefund,
                    'description' => "صرف مرتجع رقم {$return->return_number} من الفاتورة {$invoice->invoice_number}",
                    'created_by' => $authenticatedUser instanceof User ? $authenticatedUser->id : null,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Audit Log
            |--------------------------------------------------------------------------
            */

            activity()
                ->performedOn($return)
                ->withProperties([
                    'invoice_id' => $invoice->id
                ])
                ->log('return_created');

            /*
            |--------------------------------------------------------------------------
            | تحديث المرتجع داخل الوردية
            |--------------------------------------------------------------------------
            */

            $user = auth()->user();

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

                // Cash refunds reduce the amount that should remain in the drawer.
                $shift->update([
                    'returns_amount' => ($shift->returns_amount ?? 0) + $cashRefund,
                ]);

                // ربط المرتجع بالوردية
                $return->update([
                    'shift_id' => $shift->id
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'تم إنشاء فاتورة المرتجع بنجاح',
                'data'    => new ReturnInvoiceResource(
                    $return->load(['items', 'invoice.items'])
                )
            ], 201);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $e instanceof \RuntimeException ? 400 : 500);
        }
    }

    private function findInvoiceItem(Invoice $invoice, array $returnItem)
    {
        $items = $invoice->items->where('product_id', $returnItem['product_id']);
        $color = $returnItem['color'] ?? null;
        $size = $returnItem['size'] ?? null;

        if ($color !== null || $size !== null) {
            return $items->first(function ($invoiceItem) use ($color, $size) {
                return ($color === null || $invoiceItem->color === $color)
                    && ($size === null || $invoiceItem->size === $size);
            });
        }

        return $items->first();
    }

    public function invoiceReturnIndex(Request $request)
    {
        try {
            $filters = $request->input('filters', []);
            $orderBy = $request->input('orderBy', 'id');
            $orderByDirection = $request->input('orderByDirection', 'desc');
            $perPage = $request->input('perPage', 10);
            $paginate = $request->boolean('paginate', true);

            $query = ReturnInvoice::with(['items', 'invoice.customer', 'invoice.items']);

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

            if (!empty($filters['refund_method'])) {
                $query->where('refund_method', $filters['refund_method']);
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
                $returns = $query->paginate($perPage);

                return response()->json([
                    'data' => ReturnInvoiceResource::collection($returns->items()),
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
                    'message' => 'Return invoices fetched successfully',
                    'status' => 200,
                ]);
            }

            // =========================
            // NON PAGINATED MODE
            // =========================
            $returns = $query->get();

            return response()->json([
                'data' => ReturnInvoiceResource::collection($returns),
                'links' => null,
                'meta' => null,
                'result' => 'Success',
                'message' => 'Return invoices fetched successfully',
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




}
