<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseInventoryReportController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'movement_warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'product_id' => 'nullable|integer|exists:products,id',
            'search' => 'nullable|string|max:100',
            'type' => 'nullable|in:all,purchase,purchase_return,sale,sales_return,pos_sale,pos_return,adjustment,opening',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'movement_page' => 'nullable|integer|min:1',
        ]);

        $perPage = $filters['per_page'] ?? 25;
        $page = $filters['page'] ?? 1;
        $movementPage = $filters['movement_page'] ?? 1;

        $warehouses = DB::table('warehouses')
            ->select('id', 'name', 'branch_id')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        $warehouseTotals = DB::table('product_warehouse as pw')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->join('warehouses as w', 'w.id', '=', 'pw.warehouse_id')
            ->selectRaw('pw.warehouse_id, COUNT(DISTINCT pw.product_id) as products_count')
            ->selectRaw('COALESCE(SUM(pw.stock), 0) as total_stock')
            ->selectRaw('COALESCE(SUM(pw.stock * COALESCE(pw.cost, p.cost, 0)), 0) as stock_value')
            ->whereNull('p.deleted_at')
            ->whereNull('w.deleted_at')
            ->groupBy('pw.warehouse_id')
            ->get()
            ->keyBy('warehouse_id');

        $warehouses = $warehouses->map(function ($warehouse) use ($warehouseTotals) {
            $totals = $warehouseTotals->get($warehouse->id);
            $warehouse->products_count = (int) ($totals->products_count ?? 0);
            $warehouse->total_stock = (float) ($totals->total_stock ?? 0);
            $warehouse->stock_value = round((float) ($totals->stock_value ?? 0), 2);
            return $warehouse;
        });

        $balanceQuery = DB::table('product_warehouse as pw')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->join('warehouses as w', 'w.id', '=', 'pw.warehouse_id')
            ->select([
                'pw.warehouse_id',
                'w.name as warehouse_name',
                'pw.product_id',
                'p.name as product_name',
                'p.sku',
                'p.barcode',
                'pw.stock',
                'pw.cost as warehouse_cost',
                'p.cost as product_cost',
            ])
            ->selectRaw('COALESCE(pw.cost, p.cost, 0) as unit_cost')
            ->selectRaw('(pw.stock * COALESCE(pw.cost, p.cost, 0)) as stock_value')
            ->whereNull('p.deleted_at')
            ->whereNull('w.deleted_at');

        if (!empty($filters['warehouse_id'])) {
            $balanceQuery->where('pw.warehouse_id', $filters['warehouse_id']);
        }
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $balanceQuery->where(function (Builder $query) use ($search) {
                $query->where('p.name', 'like', "%{$search}%")
                    ->orWhere('p.sku', 'like', "%{$search}%")
                    ->orWhere('p.barcode', 'like', "%{$search}%");
            });
        }

        $balanceTotal = (clone $balanceQuery)->count();
        $balances = $balanceQuery
            ->orderBy('w.name')
            ->orderBy('p.name')
            ->forPage($page, $perPage)
            ->get()
            ->map(function ($row) {
                $row->stock = (float) $row->stock;
                $row->unit_cost = round((float) $row->unit_cost, 2);
                $row->stock_value = round((float) $row->stock_value, 2);
                return $row;
            });

        $selectedProduct = $filters['product_id'] ?? null;
        $movements = collect();
        $movementMeta = [
            'current_page' => $movementPage,
            'per_page' => 50,
            'total' => 0,
            'last_page' => 1,
        ];

        if ($selectedProduct) {
            $movementWarehouseId = $filters['movement_warehouse_id'] ?? $filters['warehouse_id'] ?? null;
            $movementQueries = $this->movementQueries($selectedProduct, $movementWarehouseId);
            $movementUnion = array_shift($movementQueries);
            foreach ($movementQueries as $query) {
                $movementUnion->unionAll($query);
            }

            $movementQuery = DB::query()->fromSub($movementUnion, 'movements');
            if (!empty($filters['date_from'])) {
                $movementQuery->whereDate('movement_date', '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $movementQuery->whereDate('movement_date', '<=', $filters['date_to']);
            }
            if (!empty($filters['type']) && $filters['type'] !== 'all' && $filters['type'] !== 'opening') {
                $movementQuery->where('type', $filters['type']);
            } elseif (($filters['type'] ?? null) === 'opening') {
                $movementQuery->whereRaw('1 = 0');
            }

            $movementTotal = (clone $movementQuery)->count();
            $movementRows = $movementQuery
                ->orderByDesc('movement_date')
                ->orderByDesc('id')
                ->forPage($movementPage, 50)
                ->get();

            $openingRows = $this->openingMovements(
                $selectedProduct,
                $movementWarehouseId,
                $filters['type'] ?? 'all',
                $filters['date_from'] ?? null
            );

            $movements = $movementRows->map(function ($row) {
                $row->quantity = (float) $row->quantity;
                $row->unit_cost = round((float) $row->unit_cost, 2);
                $row->amount = round((float) $row->amount, 2);
                return $row;
            });
            if ($movementPage === 1) {
                $movements = $openingRows->concat($movements);
            }

            $total = $movementTotal + $openingRows->count();
            $movementMeta = [
                'current_page' => $movementPage,
                'per_page' => 50,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / 50)),
            ];
        }

        $summary = [
            'warehouses_count' => $warehouses->count(),
            'products_count' => (int) $warehouseTotals->sum('products_count'),
            'total_stock' => (float) $warehouseTotals->sum('total_stock'),
            'stock_value' => round((float) $warehouseTotals->sum('stock_value'), 2),
        ];

        return response()->json([
            'result' => 'Success',
            'message' => 'Warehouse inventory report fetched successfully',
            'data' => [
                'summary' => $summary,
                'warehouses' => $warehouses,
                'balances' => $balances,
                'balance_meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $balanceTotal,
                    'last_page' => max(1, (int) ceil($balanceTotal / $perPage)),
                ],
                'movements' => $movements,
                'movement_meta' => $movementMeta,
                'opening_balance_note' => 'Opening quantities are estimated from current warehouse stock minus recorded warehouse-linked purchases, returns, sales, and inventory adjustments. Older POS invoices do not store a warehouse, so they are shown as unassigned movements and cannot be allocated to a warehouse.',
                'valuation_note' => 'Inventory value uses product_warehouse cost when available and falls back to the product cost. It is an estimate, not a historical FIFO valuation.',
            ],
            'status' => 200,
        ]);
    }

    private function movementQueries(int $productId, ?int $warehouseId): array
    {
        $purchases = DB::table('purchase_invoice_items as item')
            ->join('purchase_invoices as invoice', 'invoice.id', '=', 'item.purchase_invoice_id')
            ->join('products as product', 'product.id', '=', 'item.product_id')
            ->leftJoin('units as unit', 'unit.id', '=', 'item.product_unit_id')
            ->leftJoin('colors as color', 'color.id', '=', 'item.color_id')
            ->where('item.product_id', $productId)
            ->whereNull('item.deleted_at')
            ->whereNull('invoice.deleted_at')
            ->selectRaw("'purchase' as type, item.id, COALESCE(invoice.invoice_date, invoice.created_at) as movement_date")
            ->selectRaw('invoice.warehouse_id, item.product_id, product.name as product_name')
            ->selectRaw("CONCAT_WS(' / ', unit.name, color.name) as variant")
            ->selectRaw('item.quantity as quantity, item.price as unit_cost, (item.quantity * item.price) as amount')
            ->selectRaw('invoice.invoice_number as reference, invoice.note as note');

        $purchaseReturns = DB::table('purchase_return_items as item')
            ->join('purchase_returns as ret', 'ret.id', '=', 'item.purchase_return_id')
            ->join('purchase_invoices as invoice', 'invoice.id', '=', 'ret.purchase_invoices_id')
            ->join('products as product', 'product.id', '=', 'item.product_id')
            ->leftJoin('units as unit', 'unit.id', '=', 'item.product_unit_id')
            ->leftJoin('colors as color', 'color.id', '=', 'item.color_id')
            ->where('item.product_id', $productId)
            ->whereNull('ret.deleted_at')
            ->whereNull('invoice.deleted_at')
            ->selectRaw("'purchase_return' as type, item.id, COALESCE(ret.return_date, ret.created_at) as movement_date")
            ->selectRaw('COALESCE(ret.warehouse_id, invoice.warehouse_id) as warehouse_id, item.product_id, product.name as product_name')
            ->selectRaw("CONCAT_WS(' / ', unit.name, color.name) as variant")
            ->selectRaw('-item.quantity as quantity, item.unit_price as unit_cost, -(item.quantity * item.unit_price) as amount')
            ->selectRaw('ret.return_number as reference, ret.reason as note');

        $sales = DB::table('sales_invoice_items as item')
            ->join('sales_invoices as invoice', 'invoice.id', '=', 'item.sales_invoice_id')
            ->join('products as product', 'product.id', '=', 'item.product_id')
            ->leftJoin('units as unit', 'unit.id', '=', 'item.product_unit_id')
            ->leftJoin('colors as color', 'color.id', '=', 'item.color_id')
            ->where('item.product_id', $productId)
            ->whereNull('item.deleted_at')
            ->whereNull('invoice.deleted_at')
            ->selectRaw("'sale' as type, item.id, COALESCE(invoice.invoice_date, invoice.created_at) as movement_date")
            ->selectRaw('invoice.warehouse_id, item.product_id, product.name as product_name')
            ->selectRaw("CONCAT_WS(' / ', unit.name, color.name) as variant")
            ->selectRaw('-item.quantity as quantity, COALESCE(product.cost, item.price, 0) as unit_cost')
            ->selectRaw('-(item.quantity * COALESCE(product.cost, item.price, 0)) as amount')
            ->selectRaw('invoice.invoice_number as reference, invoice.note as note');

        $salesReturns = DB::table('sales_invoice_return_items as item')
            ->join('sales_invoice_returns as ret', 'ret.id', '=', 'item.sales_invoice_return_id')
            ->join('sales_invoices as invoice', 'invoice.id', '=', 'ret.sales_invoice_id')
            ->join('products as product', 'product.id', '=', 'item.product_id')
            ->leftJoin('units as unit', 'unit.id', '=', 'item.product_unit_id')
            ->leftJoin('colors as color', 'color.id', '=', 'item.color_id')
            ->where('item.product_id', $productId)
            ->whereNull('ret.deleted_at')
            ->whereNull('invoice.deleted_at')
            ->selectRaw("'sales_return' as type, item.id, ret.created_at as movement_date")
            ->selectRaw('invoice.warehouse_id, item.product_id, product.name as product_name')
            ->selectRaw("CONCAT_WS(' / ', unit.name, color.name) as variant")
            ->selectRaw('item.quantity as quantity, COALESCE(product.cost, item.price, 0) as unit_cost')
            ->selectRaw('(item.quantity * COALESCE(product.cost, item.price, 0)) as amount')
            ->selectRaw('ret.return_number as reference, ret.note as note');

        $adjustments = DB::table('inventory_logs as log')
            ->join('products as product', 'product.id', '=', 'log.product_id')
            ->leftJoin('product_warehouse as pw', function ($join) {
                $join->on('pw.product_id', '=', 'log.product_id')
                    ->on('pw.warehouse_id', '=', 'log.warehouse_id');
            })
            ->where('log.product_id', $productId)
            ->whereNull('log.deleted_at')
            ->selectRaw("'adjustment' as type, log.id, log.created_at as movement_date")
            ->selectRaw('log.warehouse_id, log.product_id, product.name as product_name, NULL as variant')
            ->selectRaw('log.difference as quantity, COALESCE(pw.cost, product.cost, 0) as unit_cost')
            ->selectRaw('(log.difference * COALESCE(pw.cost, product.cost, 0)) as amount')
            ->selectRaw("CONCAT('COUNT-', log.id) as reference, log.note as note");

        $posSales = DB::table('invoice_items as item')
            ->join('invoices as invoice', 'invoice.id', '=', 'item.invoice_id')
            ->join('products as product', 'product.id', '=', 'item.product_id')
            ->where('item.product_id', $productId)
            ->whereNull('invoice.deleted_at')
            ->selectRaw("'pos_sale' as type, item.id, invoice.created_at as movement_date")
            ->selectRaw('NULL as warehouse_id, item.product_id, product.name as product_name')
            ->selectRaw("CONCAT_WS(' / ', item.size, item.color) as variant")
            ->selectRaw('-item.quantity as quantity, COALESCE(product.cost, item.price, 0) as unit_cost')
            ->selectRaw('-(item.quantity * COALESCE(product.cost, item.price, 0)) as amount')
            ->selectRaw('invoice.invoice_number as reference, NULL as note');

        $posReturns = DB::table('return_items as item')
            ->join('return_invoices as ret', 'ret.id', '=', 'item.return_invoice_id')
            ->join('invoices as invoice', 'invoice.id', '=', 'ret.invoice_id')
            ->join('products as product', 'product.id', '=', 'item.product_id')
            ->where('item.product_id', $productId)
            ->whereNull('invoice.deleted_at')
            ->selectRaw("'pos_return' as type, item.id, ret.created_at as movement_date")
            ->selectRaw('NULL as warehouse_id, item.product_id, product.name as product_name')
            ->selectRaw("CONCAT_WS(' / ', item.size, item.color) as variant")
            ->selectRaw('item.quantity as quantity, COALESCE(product.cost, item.price, 0) as unit_cost')
            ->selectRaw('(item.quantity * COALESCE(product.cost, item.price, 0)) as amount')
            ->selectRaw('ret.return_number as reference, ret.reason as note');

        $queries = [$purchases, $purchaseReturns, $sales, $salesReturns, $adjustments, $posSales, $posReturns];
        if ($warehouseId) {
            foreach ($queries as $index => $query) {
                if ($index >= 5) {
                    continue;
                } elseif ($index === 1) {
                    $query->whereRaw('COALESCE(ret.warehouse_id, invoice.warehouse_id) = ?', [$warehouseId]);
                } elseif ($index === 4) {
                    $query->where('log.warehouse_id', $warehouseId);
                } else {
                    $query->where('warehouse_id', $warehouseId);
                }
            }
        }

        return $queries;
    }

    private function openingMovements(int $productId, ?int $warehouseId, string $type, ?string $dateFrom)
    {
        if ($type !== 'all' && $type !== 'opening') {
            return collect();
        }

        $movementQueries = $this->movementQueries($productId, null);
        $netMovements = [];
        foreach ($movementQueries as $query) {
            if ($dateFrom) {
                $query->whereDate('movement_date', '>=', $dateFrom);
            }
            $netQuery = DB::query()->fromSub($query, 'source')
                ->selectRaw('warehouse_id, product_id, SUM(quantity) as net_quantity')
                ->groupBy('warehouse_id', 'product_id');
            foreach ($netQuery->get() as $row) {
                $key = $row->warehouse_id . ':' . $row->product_id;
                $netMovements[$key] = ($netMovements[$key] ?? 0) + (float) $row->net_quantity;
            }
        }

        $currentStocks = DB::table('product_warehouse as pw')
            ->join('warehouses as w', 'w.id', '=', 'pw.warehouse_id')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->where('pw.product_id', $productId)
            ->whereNull('w.deleted_at')
            ->select('pw.warehouse_id', 'w.name as warehouse_name', 'pw.product_id', 'p.name as product_name', 'pw.stock', 'pw.cost', 'p.cost as product_cost', 'pw.created_at');
        if ($warehouseId) {
            $currentStocks->where('pw.warehouse_id', $warehouseId);
        }

        return $currentStocks->get()->map(function ($stock) use ($netMovements) {
            $key = $stock->warehouse_id . ':' . $stock->product_id;
            $openingQuantity = (float) $stock->stock - ($netMovements[$key] ?? 0);
            $unitCost = (float) ($stock->cost ?? $stock->product_cost ?? 0);
            return (object) [
                'type' => 'opening',
                'id' => 'opening-' . $key,
                'movement_date' => $dateFrom,
                'warehouse_id' => $stock->warehouse_id,
                'warehouse_name' => $stock->warehouse_name,
                'product_id' => $stock->product_id,
                'product_name' => $stock->product_name,
                'variant' => null,
                'quantity' => $openingQuantity,
                'unit_cost' => $unitCost,
                'amount' => round($openingQuantity * $unitCost, 2),
                'reference' => null,
                'note' => 'Calculated from current stock and recorded movements',
            ];
        })->filter(fn ($row) => abs($row->quantity) > 0.00001)->values();
    }
}
