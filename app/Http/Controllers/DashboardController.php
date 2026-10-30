<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        $now = Carbon::now();
        $today = $now->toDateString();
        $yesterday = $now->copy()->subDay()->toDateString();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfDay();
        $defaultCurrency = DB::table('currencies')->where('default', true)->whereNull('deleted_at')->first(['code', 'symbol', 'exchange_rate']);
        $defaultRate = (float) ($defaultCurrency?->exchange_rate ?: 1);

        $sales = DB::query()->fromSub($this->salesQuery($branchId, $defaultRate), 'sales');
        $todaySales = (float) (clone $sales)->whereDate('created_at', $today)->sum('amount');
        $yesterdaySales = (float) (clone $sales)->whereDate('created_at', $yesterday)->sum('amount');
        $monthSales = (float) (clone $sales)->whereBetween('created_at', [$monthStart, $monthEnd])->sum('amount');
        $monthOrders = (int) (clone $sales)->whereBetween('created_at', [$monthStart, $monthEnd])->count();
        $salesChange = $yesterdaySales > 0
            ? round((($todaySales - $yesterdaySales) / $yesterdaySales) * 100, 1)
            : null;

        $monthExpensesQuery = DB::table('finances')
            ->leftJoin('currencies as expense_currency', 'expense_currency.id', '=', 'finances.currency_id')
            ->whereNull('finances.deleted_at')
            ->whereBetween('date', [$monthStart->toDateString(), $today]);
        if ($branchId) $monthExpensesQuery->where('finances.branch_id', $branchId);
        $monthExpenses = (float) $monthExpensesQuery->sum(DB::raw('COALESCE(finances.amount, 0) * ' . $defaultRate . ' / COALESCE(NULLIF(CAST(expense_currency.exchange_rate AS DECIMAL(18, 8)), 0), ' . $defaultRate . ')'));

        $lowStockQuery = DB::table('product_warehouse as pw')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->join('warehouses as w', 'w.id', '=', 'pw.warehouse_id')
            ->whereNull('p.deleted_at')
            ->whereNull('w.deleted_at')
            ->where('p.active', true)
            ->whereRaw('pw.stock <= CASE WHEN p.reorder_level > 0 THEN p.reorder_level ELSE 5 END');
        if ($branchId) $lowStockQuery->where('w.branch_id', $branchId);
        $lowStockCount = (int) $lowStockQuery->count();

        $trendStart = $now->copy()->startOfMonth()->subMonths(11);
        $trendRows = (clone $sales)
            ->where('created_at', '>=', $trendStart)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month_key, SUM(amount) as revenue")
            ->groupBy('month_key')
            ->get()
            ->keyBy('month_key');
        $salesTrend = [];
        for ($offset = 11; $offset >= 0; $offset--) {
            $month = $now->copy()->startOfMonth()->subMonths($offset);
            $key = $month->format('Y-m');
            $salesTrend[] = [
                'month' => $key,
                'revenue' => round((float) ($trendRows->get($key)->revenue ?? 0), 2),
            ];
        }

        $categorySales = $this->categorySales($branchId, $monthStart, $monthEnd, $defaultRate);

        $branchRevenues = DB::query()->fromSub($this->salesQuery($branchId, $defaultRate), 'sales')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->whereNotNull('branch_id')
            ->selectRaw('branch_id, SUM(amount) as revenue')
            ->groupBy('branch_id')
            ->orderByDesc('revenue')
            ->get()
            ->map(function ($row) {
                $branch = DB::table('branches')->where('id', $row->branch_id)->first(['name']);
                return [
                    'branch_id' => (int) $row->branch_id,
                    'branch_name' => $branch?->name ?? '—',
                    'branch_name_ar' => null,
                    'revenue' => round((float) $row->revenue, 2),
                ];
            })
            ->values();

        $recentTransactions = $this->recentTransactions($branchId, $defaultRate);

        return response()->json([
            'result' => 'Success',
            'message' => 'Dashboard overview fetched successfully',
            'data' => [
                'currency_code' => $defaultCurrency?->code ?: 'EGP',
                'currency_symbol' => $defaultCurrency?->symbol ?: 'ج.م',
                'metrics' => [
                    'today_sales' => round($todaySales, 2),
                    'yesterday_sales' => round($yesterdaySales, 2),
                    'sales_change_percent' => $salesChange,
                    'month_sales' => round($monthSales, 2),
                    'month_orders' => $monthOrders,
                    'month_expenses' => round($monthExpenses, 2),
                    'average_order_value' => $monthOrders > 0 ? round($monthSales / $monthOrders, 2) : 0,
                    'low_stock_count' => $lowStockCount,
                ],
                'sales_trend' => $salesTrend,
                'top_categories' => $categorySales,
                'branch_revenues' => $branchRevenues,
                'recent_transactions' => $recentTransactions,
            ],
            'status' => 200,
        ]);
    }

    /** Both POS invoices and sales invoices are real sales streams in this system. */
    private function salesQuery(?int $branchId, float $defaultRate)
    {
        $posSales = DB::table('invoices as invoice')
            ->whereNull('invoice.deleted_at')
            ->when($branchId, fn ($query) => $query->where('invoice.branch_id', $branchId))
            ->selectRaw("'sale' as type, CONCAT('pos-', invoice.id) as id, invoice.invoice_number, invoice.customer_id, NULL as supplier_id, invoice.branch_id, NULL as payment_method, invoice.total_amount as amount, invoice.created_at");

        $salesInvoices = DB::table('sales_invoices as invoice')
            ->leftJoin('currencies as currency', 'currency.id', '=', 'invoice.currency_id')
            ->whereNull('invoice.deleted_at')
            ->when($branchId, fn ($query) => $query->where('invoice.branch_id', $branchId))
            ->selectRaw("'sale' as type, CONCAT('sales-', invoice.id) as id, invoice.invoice_number, invoice.customer_id, NULL as supplier_id, invoice.branch_id, invoice.payment_method, (COALESCE(invoice.net_total, invoice.total_amount, 0) * ? / COALESCE(NULLIF(CAST(currency.exchange_rate AS DECIMAL(18, 8)), 0), ?)) as amount, invoice.created_at", [$defaultRate, $defaultRate]);

        return $posSales->unionAll($salesInvoices);
    }

    private function categorySales(?int $branchId, Carbon $from, Carbon $to, float $defaultRate)
    {
        $pos = DB::table('invoice_items as item')
            ->join('invoices as invoice', 'invoice.id', '=', 'item.invoice_id')
            ->join('products as product', 'product.id', '=', 'item.product_id')
            ->join('categories as category', 'category.id', '=', 'product.category_id')
            ->whereNull('invoice.deleted_at')->whereNull('category.deleted_at')
            ->whereBetween('invoice.created_at', [$from, $to])
            ->when($branchId, fn ($query) => $query->where('invoice.branch_id', $branchId))
            ->selectRaw('category.id as category_id, category.name as category_name, SUM(COALESCE(item.quantity, 0)) as total_quantity, SUM(COALESCE(item.total, item.price * item.quantity, 0)) as total_revenue')
            ->groupBy('category.id', 'category.name');

        $sales = DB::table('sales_invoice_items as item')
            ->join('sales_invoices as invoice', 'invoice.id', '=', 'item.sales_invoice_id')
            ->leftJoin('currencies as currency', 'currency.id', '=', 'invoice.currency_id')
            ->join('products as product', 'product.id', '=', 'item.product_id')
            ->join('categories as category', 'category.id', '=', 'product.category_id')
            ->whereNull('invoice.deleted_at')->whereNull('item.deleted_at')->whereNull('category.deleted_at')
            ->whereBetween('invoice.created_at', [$from, $to])
            ->when($branchId, fn ($query) => $query->where('invoice.branch_id', $branchId))
            ->selectRaw('category.id as category_id, category.name as category_name, SUM(COALESCE(item.quantity, 0)) as total_quantity, SUM(COALESCE(item.total, item.price * item.quantity, 0) * ? / COALESCE(NULLIF(CAST(currency.exchange_rate AS DECIMAL(18, 8)), 0), ?)) as total_revenue', [$defaultRate, $defaultRate])
            ->groupBy('category.id', 'category.name');

        return DB::query()->fromSub($pos->unionAll($sales), 'category_sales')
            ->selectRaw('category_id, category_name, SUM(total_quantity) as total_quantity, SUM(total_revenue) as total_revenue')
            ->groupBy('category_id', 'category_name')
            ->orderByDesc('total_revenue')
            ->limit(6)
            ->get()
            ->map(function ($category) {
                return [
                    'category_id' => (int) $category->category_id,
                    'category_name' => $category->category_name ?: 'بدون تصنيف',
                    'total_quantity' => (float) $category->total_quantity,
                    'total_revenue' => round((float) $category->total_revenue, 2),
                ];
            })
            ->values();
    }

    private function recentTransactions(?int $branchId, float $defaultRate)
    {
        $sales = $this->salesQuery($branchId, $defaultRate);
        $purchases = DB::table('purchase_invoices as invoice')
            ->leftJoin('currencies as currency', 'currency.id', '=', 'invoice.currency_id')
            ->whereNull('invoice.deleted_at')
            ->when($branchId, fn ($query) => $query->where('invoice.branch_id', $branchId))
            ->selectRaw("'purchase' as type, CONCAT('purchase-', invoice.id) as id, invoice.invoice_number, NULL as customer_id, invoice.supplier_id, invoice.branch_id, invoice.payment_method, (COALESCE(invoice.total_amount, 0) * ? / COALESCE(NULLIF(CAST(currency.exchange_rate AS DECIMAL(18, 8)), 0), ?)) as amount, COALESCE(invoice.invoice_date, invoice.created_at) as created_at", [$defaultRate, $defaultRate]);

        return DB::query()->fromSub($sales->unionAll($purchases), 'transaction')
            ->leftJoin('customers', 'customers.id', '=', 'transaction.customer_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'transaction.supplier_id')
            ->leftJoin('branches', 'branches.id', '=', 'transaction.branch_id')
            ->orderByDesc('transaction.created_at')
            ->limit(8)
            ->get([
                'transaction.type', 'transaction.id', 'transaction.invoice_number',
                'transaction.payment_method', 'transaction.amount as total_amount',
                'transaction.created_at', 'branches.name as branch_name',
                'customers.name as customer_name',
                'suppliers.name as supplier_name',
            ])
            ->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'invoice_number' => $transaction->invoice_number,
                    'party_name' => $transaction->type === 'purchase'
                        ? ($transaction->supplier_name ?: 'مورد')
                        : ($transaction->customer_name ?: 'عميل'),
                    'branch_name' => $transaction->branch_name ?: '—',
                    'branch_name_ar' => null,
                    'payment_method' => $transaction->payment_method,
                    'total_amount' => round((float) $transaction->total_amount, 2),
                    'created_at' => $transaction->created_at,
                ];
            })
            ->values();
    }
}
