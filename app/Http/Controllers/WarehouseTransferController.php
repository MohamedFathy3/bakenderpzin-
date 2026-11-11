<?php


namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseTransferController extends Controller
{
    /**
     * سجل مستندات تحويل المخازن.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:150',
            'from_warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'to_warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = DB::table('warehouse_transfers as wt')
            ->leftJoin('warehouses as source', 'source.id', '=', 'wt.from_warehouse_id')
            ->leftJoin('warehouses as destination', 'destination.id', '=', 'wt.to_warehouse_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'wt.created_by')
            ->select([
                'wt.id',
                'wt.id as transfer_number',
                'wt.created_at as transfer_date',
                'wt.from_warehouse_id',
                'wt.to_warehouse_id',
                'wt.inventory_value',
                'wt.created_by',
                'wt.created_at',
                'source.name as source_warehouse_name',
                'destination.name as destination_warehouse_name',
                'creator.name as creator_name',
            ])
            ->selectSub(function ($sub) {
                $sub->from('warehouse_transfer_items')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn(
                        'warehouse_transfer_items.warehouse_transfer_id',
                        'wt.id'
                    );
            }, 'items_count')
            ->selectSub(function ($sub) {
                $sub->from('warehouse_transfer_items')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn(
                        'warehouse_transfer_items.warehouse_transfer_id',
                        'wt.id'
                    );
            }, 'total_quantity');

        if (!empty($validated['search'])) {
            $search = trim($validated['search']);

            $query->where(function ($q) use ($search) {
                $q->where('wt.id', 'like', "%{$search}%")
                    ->orWhere('source.name', 'like', "%{$search}%")
                    ->orWhere('destination.name', 'like', "%{$search}%")
                    ->orWhere('creator.name', 'like', "%{$search}%");
            });
        }

        if (!empty($validated['from_warehouse_id'])) {
            $query->where(
                'wt.from_warehouse_id',
                $validated['from_warehouse_id']
            );
        }

        if (!empty($validated['to_warehouse_id'])) {
            $query->where(
                'wt.to_warehouse_id',
                $validated['to_warehouse_id']
            );
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate(
                'wt.created_at',
                '>=',
                $validated['date_from']
            );
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate(
                'wt.created_at',
                '<=',
                $validated['date_to']
            );
        }

        $perPage = (int) ($validated['per_page'] ?? 15);

        $transfers = $query
            ->orderByDesc('wt.created_at')
            ->orderByDesc('wt.id')
            ->paginate($perPage);

        return response()->json([
            'result' => 'Success',
            'message' => 'Warehouse transfers fetched successfully',
            'data' => $transfers->items(),
            'pagination' => [
                'current_page' => $transfers->currentPage(),
                'last_page' => $transfers->lastPage(),
                'per_page' => $transfers->perPage(),
                'total' => $transfers->total(),
                'from' => $transfers->firstItem(),
                'to' => $transfers->lastItem(),
            ],
            'status' => 200,
        ]);
    }

    /**
     * تفاصيل مستند تحويل محدد.
     */
    public function show(int $id)
    {
        $transfer = DB::table('warehouse_transfers as wt')
            ->leftJoin('warehouses as source', 'source.id', '=', 'wt.from_warehouse_id')
            ->leftJoin('warehouses as destination', 'destination.id', '=', 'wt.to_warehouse_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'wt.created_by')
            ->where('wt.id', $id)
            ->select([
                'wt.*',
                'wt.id as transfer_number',
                'wt.created_at as transfer_date',
                'source.name as source_warehouse_name',
                'destination.name as destination_warehouse_name',
                'creator.name as creator_name',
            ])
            ->first();

        if (!$transfer) {
            return response()->json([
                'result' => 'Error',
                'message' => 'Transfer document not found',
                'status' => 404,
            ], 404);
        }

        $items = DB::table('warehouse_transfer_items as item')
            ->leftJoin('products as product', 'product.id', '=', 'item.product_id')
            ->leftJoin('product_units as pu', 'pu.id', '=', 'item.product_unit_id')
            ->leftJoin('units as unit', 'unit.id', '=', 'pu.unit_id')
            ->leftJoin('colors as color', 'color.id', '=', 'item.color_id')
            ->where('item.warehouse_transfer_id', $id)
            ->select([
                'item.id',
                'item.warehouse_transfer_id',
                'item.product_id',
                'product.name as product_name',
                'product.barcode as product_barcode',
                'item.product_unit_id',
                'unit.name as unit_name',
                'pu.barcode as unit_barcode',
                'item.color_id',
                'color.name as color_name',
                'item.quantity',
                'item.source_before',
                'item.source_after',
                'item.destination_before',
                'item.destination_after',
                'item.unit_cost',
                'item.total_cost',
                'item.note',
            ])
            ->orderBy('item.id')
            ->get();

        return response()->json([
            'result' => 'Success',
            'message' => 'Transfer details fetched successfully',
            'data' => [
                'transfer' => $transfer,
                'items' => $items,
                'items_count' => $items->count(),
                'total_quantity' => (int) $items->sum('quantity'),
                'inventory_value' => (float) $transfer->inventory_value,
            ],
            'status' => 200,
        ]);
    }
}
