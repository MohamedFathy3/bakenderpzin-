<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemAlertController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['branch_id' => 'nullable|integer|exists:branches,id']);
        $branchId = $request->query('branch_id');
        $limit = min(max((int) $request->query('limit', 200), 1), 500);
        $alerts = collect();

        $stocks = DB::table('product_warehouse as pw')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->join('warehouses as w', 'w.id', '=', 'pw.warehouse_id')
            ->whereNull('p.deleted_at')->whereNull('w.deleted_at')
            ->where('p.active', true)
            ->when($branchId, fn ($query) => $query->where('w.branch_id', $branchId))
            ->whereRaw('pw.stock <= CASE WHEN p.reorder_level > 0 THEN p.reorder_level ELSE 5 END')
            ->select([
                'pw.product_id', 'pw.warehouse_id', 'pw.stock', 'p.name as product_name',
                'p.sku', 'p.barcode', 'p.reorder_level', 'w.name as warehouse_name',
            ])
            ->orderBy('pw.stock')->limit($limit)->get();

        foreach ($stocks as $row) {
            $out = (int) $row->stock <= 0;
            $alerts->push([
                'id' => 'stock:' . $row->warehouse_id . ':' . $row->product_id,
                'category' => $out ? 'out_of_stock' : 'low_stock',
                'type' => $out ? 'error' : 'warning',
                'title' => $out ? 'نفاد المخزون' : 'انخفاض المخزون',
                'title_en' => $out ? 'Out of stock' : 'Low stock',
                'message' => ($row->product_name ?: 'منتج') . ' — ' . ($row->warehouse_name ?: 'مخزن') . ' (الرصيد ' . $row->stock . '، الحد ' . ($row->reorder_level ?: 5) . ')',
                'message_en' => ($row->product_name ?: 'Product') . ' — ' . ($row->warehouse_name ?: 'Warehouse') . ' (stock ' . $row->stock . ', threshold ' . ($row->reorder_level ?: 5) . ')',
                'product_name' => $row->product_name,
                'sku' => $row->sku,
                'barcode' => $row->barcode,
                'warehouse_name' => $row->warehouse_name,
                'current_quantity' => (int) $row->stock,
                'threshold_quantity' => (int) ($row->reorder_level ?: 5),
                'reference_type' => 'product',
                'reference_id' => (int) $row->product_id,
                'created_at' => now()->toISOString(),
            ]);
        }

        if (DB::getSchemaBuilder()->hasTable('purchase_invoices')) {
            $invoices = DB::table('purchase_invoices as invoice')
                ->leftJoin('suppliers as supplier', 'supplier.id', '=', 'invoice.supplier_id')
                ->whereNull('invoice.deleted_at')
                ->when($branchId, fn ($query) => $query->where('invoice.branch_id', $branchId))
                ->whereNotNull('invoice.due_date')->whereDate('invoice.due_date', '<', now()->toDateString())
                ->whereRaw('(invoice.total_amount - invoice.paid_amount) > 0.01')
                ->select([
                    'invoice.id', 'invoice.invoice_number', 'invoice.due_date', 'invoice.total_amount',
                    'invoice.paid_amount', 'supplier.name as supplier_name',
                ])
                ->orderBy('invoice.due_date')->limit($limit)->get();

            foreach ($invoices as $invoice) {
                $remaining = max(0, (float) $invoice->total_amount - (float) $invoice->paid_amount);
                $alerts->push([
                    'id' => 'purchase_due:' . $invoice->id,
                    'category' => 'overdue_payable',
                    'type' => 'warning',
                    'title' => 'فاتورة مورد متأخرة',
                    'title_en' => 'Overdue supplier invoice',
                    'message' => 'فاتورة ' . ($invoice->invoice_number ?: $invoice->id) . ' — ' . ($invoice->supplier_name ?: 'مورد') . '، متبقي ' . number_format($remaining, 2),
                    'message_en' => 'Invoice ' . ($invoice->invoice_number ?: $invoice->id) . ' — ' . ($invoice->supplier_name ?: 'Supplier') . ', remaining ' . number_format($remaining, 2),
                    'product_name' => $invoice->supplier_name ?: 'مورد',
                    'sku' => $invoice->invoice_number,
                    'barcode' => null,
                    'warehouse_name' => null,
                    'current_quantity' => $remaining,
                    'threshold_quantity' => 0,
                    'reference_type' => 'purchase_invoice',
                    'reference_id' => (int) $invoice->id,
                    'created_at' => $invoice->due_date,
                ]);
            }
        }

        $sorted = $alerts->sortByDesc('created_at')->take($limit)->values();
        return response()->json([
            'result' => 'Success',
            'message' => 'System alerts fetched successfully',
            'data' => $sorted,
            'summary' => [
                'total' => $sorted->count(),
                'unread' => $sorted->count(),
                'by_category' => $sorted->countBy('category'),
            ],
            'status' => 200,
        ]);
    }
}
