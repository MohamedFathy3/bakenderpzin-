<?php

namespace App\Http\Controllers;

use App\Models\InventoryCount;
use App\Models\InventoryLog;
use App\Models\ProductUnitColorWarehouse;
use Illuminate\Http\Request;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Facades\DB;

class InventoryCountController extends Controller
{
    /**
     * قائمة مستندات الجرد (صف واحد لكل عملية جرد) بالإجماليات.
     */
    public function index(Request $request)
    {
        try {
            $q = InventoryCount::with('warehouse')
                ->withCount('logs as lines_count')
                ->withSum('logs as total_system', 'system_stock')
                ->withSum('logs as total_counted', 'counted_stock')
                ->withSum('logs as total_difference', 'difference');

            if ($w = $request->input('filters.warehouse_id')) {
                $q->where('warehouse_id', $w);
            }
            if ($f = $request->input('filters.date_from')) {
                $q->whereDate('created_at', '>=', $f);
            }
            if ($t = $request->input('filters.date_to')) {
                $q->whereDate('created_at', '<=', $t);
            }
            if ($p = $request->input('filters.product_id')) {
                $q->whereHas('logs', fn ($l) => $l->where('product_id', $p));
            }

            $q->orderByDesc('id');

            $rows = $request->boolean('paginate', true)
                ? $q->paginate((int) $request->input('perPage', 15))
                : $q->get();

            // عدد المنتجات المختلفة في كل مستند
            $items = $rows instanceof AbstractPaginator ? $rows->getCollection() : $rows;
            $ids = $items->pluck('id');

            $productsMap = InventoryLog::whereIn('inventory_count_id', $ids)
                ->selectRaw('inventory_count_id, COUNT(DISTINCT product_id) as c')
                ->groupBy('inventory_count_id')
                ->pluck('c', 'inventory_count_id');

            $items->each(function ($r) use ($productsMap) {
                $r->products_count = (int) ($productsMap[$r->id] ?? 0);
                $r->total_system = (int) $r->total_system;
                $r->total_counted = (int) $r->total_counted;
                $r->total_difference = (int) $r->total_difference;
            });

            return response()->json([
                'result' => 'Success',
                'data'   => $rows,
                'status' => 200,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'result'  => 'Error',
                'message' => $e->getMessage(),
                'status'  => 500,
            ], 500);
        }
    }

    /**
     * تفاصيل مستند جرد: الهيدر + كل السطور (منتج/مقاس/لون).
     */
    public function show(InventoryCount $inventoryCount)
    {
        try {
            return response()->json([
                'result' => 'Success',
                'data'   => $this->payload($inventoryCount),
                'status' => 200,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'result'  => 'Error',
                'message' => $e->getMessage(),
                'status'  => 500,
            ], 500);
        }
    }

    /**
     * تعديل مستند الجرد كله مرة واحدة (سطور + ملاحظة).
     * التعديل بيطبّق الفرق على الرصيد الحالي (مش بيكتب فوقه)
     * علشان أي حركة بيع/شراء بعد الجرد متضيعش.
     */
    public function update(Request $request, InventoryCount $inventoryCount)
    {
        $request->validate([
            'note'                  => 'nullable|string',
            'lines'                 => 'required|array|min:1',
            'lines.*.id'            => 'required|integer',
            'lines.*.counted_stock' => 'required|integer|min:0',
        ]);

        try {
            DB::transaction(function () use ($request, $inventoryCount) {
                $logs = $inventoryCount->logs()->lockForUpdate()->get()->keyBy('id');
                $touched = [];

                foreach ($request->lines as $l) {
                    $log = $logs->get((int) $l['id']);
                    if (!$log) {
                        throw new \RuntimeException("السطر {$l['id']} لا يتبع هذا الجرد.");
                    }

                    $new = (int) $l['counted_stock'];
                    $delta = $new - (int) $log->counted_stock;
                    if ($delta === 0) {
                        continue;
                    }

                    if ($log->product_unit_id && $log->color_id) {
                        // سطر variant (مقاس + لون)
                        $colorRec = DB::table('product_unit_colors')
                            ->where('product_unit_id', $log->product_unit_id)
                            ->where('color_id', $log->color_id)
                            ->whereNull('deleted_at')
                            ->first();

                        if (!$colorRec) {
                            throw new \RuntimeException('المقاس/اللون لم يعد موجوداً.');
                        }

                        $where = [
                            'warehouse_id'          => $log->warehouse_id,
                            'product_unit_color_id' => $colorRec->id,
                        ];

                        $cur = (int) (DB::table('product_unit_color_warehouse')
                            ->where($where)->lockForUpdate()->value('stock') ?? 0);

                        DB::table('product_unit_color_warehouse')->updateOrInsert(
                            $where,
                            ['stock' => max(0, $cur + $delta), 'updated_at' => now()]
                        );
                    } else {
                        // منتج بدون ألوان
                        DB::table('product_warehouse')
                            ->where('warehouse_id', $log->warehouse_id)
                            ->where('product_id', $log->product_id)
                            ->update([
                                'stock'      => DB::raw('GREATEST(stock + ' . (int) $delta . ', 0)'),
                                'updated_at' => now(),
                            ]);
                    }

                    $log->update([
                        'counted_stock' => $new,
                        'difference'    => $new - (int) $log->system_stock,
                    ]);

                    $touched[$log->product_id] = true;
                }

                if ($request->has('note')) {
                    $inventoryCount->update(['note' => $request->note]);
                }

                foreach (array_keys($touched) as $pid) {
                    ProductUnitColorWarehouse::syncProduct($pid);
                }
            });

            return response()->json([
                'result'  => 'Success',
                'message' => 'تم تعديل الجرد',
                'data'    => $this->payload($inventoryCount->fresh()),
                'status'  => 200,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'result'  => 'Error',
                'message' => $e->getMessage(),
                'status'  => 422,
            ], 422);
        }
    }

    private function payload(InventoryCount $c): array
    {
        // بدون تحديد أعمدة، علشان أي عمود مش موجود (name_ar / barcode ...) مايكسرش الاستعلام
        $c->load('warehouse', 'user');

        $logs = $c->logs()
            ->with('product')
            ->orderBy('id')
            ->get();

        // lookups مرة واحدة بدل N+1
        $puRows = DB::table('product_units')
            ->whereIn('id', $logs->pluck('product_unit_id')->filter()->unique())
            ->get(['id', 'unit_id'])
            ->keyBy('id');

        $unitRows = DB::table('units')
            ->whereIn('id', $puRows->pluck('unit_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $colorRows = DB::table('colors')
            ->whereIn('id', $logs->pluck('color_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $unitName = function ($puId) use ($puRows, $unitRows) {
            $pu = $puRows->get($puId);
            if (!$pu) {
                return null;
            }
            $u = $unitRows->get($pu->unit_id);
            if (!$u) {
                return null;
            }
            $a = (array) $u;
            return $a['name'] ?? $a['name_ar'] ?? $a['name_en'] ?? $a['unit_name'] ?? $a['code'] ?? null;
        };

        $colorMeta = function ($colorId) use ($colorRows) {
            $m = $colorRows->get($colorId);
            $a = $m ? (array) $m : [];
            return [
                'name' => $a['name'] ?? $a['name_ar'] ?? $a['name_en'] ?? $a['color'] ?? null,
                'hex'  => $a['hex_code'] ?? $a['code'] ?? $a['hex'] ?? null,
            ];
        };

        $lines = $logs->map(function ($l) use ($unitName, $colorMeta) {
            $meta = $l->color_id ? $colorMeta($l->color_id) : ['name' => null, 'hex' => null];

            return [
                'id'              => $l->id,
                'product_id'      => $l->product_id,
                'product_name'    => $l->product?->name,
                'product_name_ar' => $l->product?->name_ar,
                'sku'             => $l->product?->sku,
                'barcode'         => $l->product?->barcode,
                'unit_name'       => $l->product_unit_id ? $unitName($l->product_unit_id) : null,
                'color_name'      => $meta['name'],
                'color_hex'       => $meta['hex'],
                'system_stock'    => (int) $l->system_stock,
                'counted_stock'   => (int) $l->counted_stock,
                'difference'      => (int) $l->difference,
            ];
        })->values();

        return [
            'id'        => $c->id,
            'warehouse' => $c->warehouse ? [
                'id'      => $c->warehouse->id,
                'name'    => $c->warehouse->name,
                'name_ar' => $c->warehouse->name_ar ?? null,
            ] : null,
            'user'      => $c->user ? [
                'id'   => $c->user->id,
                'name' => $c->user->name ?? null,
            ] : null,
            'note'             => $c->note,
            'created_at'       => $c->created_at,
            'products_count'   => $logs->pluck('product_id')->unique()->count(),
            'lines_count'      => $lines->count(),
            'total_system'     => $lines->sum('system_stock'),
            'total_counted'    => $lines->sum('counted_stock'),
            'total_difference' => $lines->sum('difference'),
            'lines'            => $lines,
        ];
    }
}