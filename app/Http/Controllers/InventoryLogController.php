<?php

namespace App\Http\Controllers;

use App\Helpers\JsonResponse;
use App\Http\Resources\InventoryLogResource;
use App\Http\Resources\ProductResource;
use App\Interfaces\InventoryRepositoryInterface;
use App\Models\InventoryCount;
use App\Models\InventoryLog;
use App\Models\ProductWarehouse;
use App\Models\ProductUnitColorWarehouse;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InventoryLogController extends BaseController
{
    protected mixed $crudRepository;

    public function __construct(InventoryRepositoryInterface $pattern)
    {
        $this->crudRepository = $pattern;
    }

    public function index(Request $request)
    {
        try {
            $branchId = $request->input('filters.branch_id', $request->input('branch_id'));
            if (!$branchId) {
                $Inventory = InventoryLogResource::collection($this->crudRepository->all(
                    ['warehouse', 'product'], [], ['*']
                ));
                return $Inventory->additional(JsonResponse::success());
            }

            $query = InventoryLog::with(['warehouse', 'product'])->whereHas('warehouse', function ($warehouseQuery) use ($branchId) {
                $warehouseQuery->where('branch_id', $branchId);
            });
            $query->orderBy($request->input('orderBy', 'id'), $request->input('orderByDirection', 'desc'));
            $rows = $request->boolean('paginate', true)
                ? $query->paginate((int) $request->input('perPage', 15))
                : $query->get();
            return InventoryLogResource::collection($rows)->additional(JsonResponse::success());
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    /**
     * حفظ جرد كامل (أكتر من منتج / مقاس / لون) كمستند واحد في عملية واحدة.
     * أي فشل في أي سطر = rollback للعملية كلها.
     */
    public function inventoryStore(Request $request)
    {
        $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.counted_stock' => 'nullable|integer|min:0',
            'products.*.unit_id' => 'nullable|integer',
            'products.*.product_unit_id' => 'nullable|integer',
            'products.*.color_id' => 'nullable|integer',
            'products.*.variants' => 'nullable|array',
            'products.*.variants.*.product_unit_id' => 'nullable|integer',
            'products.*.variants.*.unit_id' => 'nullable|integer',
            'products.*.variants.*.color_id' => 'required_with:products.*.variants|integer',
            'products.*.variants.*.counted_stock' => 'required_with:products.*.variants|integer|min:0',
            'note' => 'nullable|string',
        ]);

        try {
            $result = DB::transaction(function () use ($request) {
                $warehouseId = (int) $request->warehouse_id;

                // ✅ هيدر واحد للعملية كلها
                $count = InventoryCount::create([
                    'warehouse_id' => $warehouseId,
                    'user_id'      => $request->user()?->id,
                    'note'         => $request->note,
                ]);

                // ============ 1) تجميع الصفوف المسطحة حسب المنتج ============
                $grouped = [];
                foreach ($request->products as $item) {
                    $productId = (int) $item['product_id'];
                    if (!isset($grouped[$productId])) {
                        $grouped[$productId] = ['variants' => [], 'counted_stock' => null];
                    }

                    // 1a) صيغة nested (variants جاهزة)
                    if (!empty($item['variants']) && is_array($item['variants'])) {
                        foreach ($item['variants'] as $v) {
                            $grouped[$productId]['variants'][] = [
                                'product_unit_id' => isset($v['product_unit_id']) ? (int) $v['product_unit_id'] : null,
                                'unit_id' => isset($v['unit_id']) ? (int) $v['unit_id'] : null,
                                'color_id' => (int) $v['color_id'],
                                'counted_stock' => (int) $v['counted_stock'],
                            ];
                        }
                        continue;
                    }

                    // 1b) صيغة مسطحة: unit_id + color_id + counted_stock
                    $unitId = isset($item['unit_id']) && $item['unit_id'] !== null ? (int) $item['unit_id'] : null;
                    $puId = isset($item['product_unit_id']) && $item['product_unit_id'] !== null ? (int) $item['product_unit_id'] : null;
                    $colorId = isset($item['color_id']) && $item['color_id'] !== null ? (int) $item['color_id'] : null;

                    if ($colorId !== null && ($unitId !== null || $puId !== null)) {
                        $grouped[$productId]['variants'][] = [
                            'unit_id' => $unitId,
                            'product_unit_id' => $puId,
                            'color_id' => $colorId,
                            'counted_stock' => (int) ($item['counted_stock'] ?? 0),
                        ];
                    } else {
                        // منتج بدون ألوان → counted_stock على مستوى المنتج
                        $grouped[$productId]['counted_stock'] = (int) ($item['counted_stock'] ?? 0);
                    }
                }

                // ============ 2) معالجة كل منتج ============
                $processedProducts = [];

                foreach ($grouped as $productId => $data) {
                    $pw = DB::table('product_warehouse')
                        ->where('warehouse_id', $warehouseId)
                        ->where('product_id', $productId)
                        ->lockForUpdate()
                        ->first();

                    if (!$pw) {
                        // المنتج مش موجود في المخزن → تخطى (مش خطأ)
                        continue;
                    }

                    $systemStock = (int) $pw->stock;

                    // هل المنتج عنده ألوان/مقاسات؟
                    $hasColors = DB::table('product_unit_colors as c')
                        ->join('product_units as u', 'u.id', '=', 'c.product_unit_id')
                        ->where('u.product_id', $productId)
                        ->whereNull('c.deleted_at')
                        ->exists();

                    // حماية: منتج عنده ألوان لكن مفيش variants → ارفض
                    if ($hasColors && empty($data['variants'])) {
                        throw new \RuntimeException(
                            "المنتج [{$productId}] له ألوان ومقاسات. لازم تحدد المقاس واللون لكل صف، أو ترسل variants."
                        );
                    }

                    if (!empty($data['variants'])) {
                        foreach ($data['variants'] as $v) {
                            $puRecordId = $v['product_unit_id'] ?? null;

                            if (!$puRecordId && !empty($v['unit_id'])) {
                                $puRecordId = DB::table('product_units')
                                    ->where('product_id', $productId)
                                    ->where('unit_id', (int) $v['unit_id'])
                                    ->whereNull('deleted_at')
                                    ->value('id');
                            }
                            if (!$puRecordId) {
                                throw new \RuntimeException("لم يتم العثور على سجل المقاس للمنتج [{$productId}].");
                            }

                            $colorRec = DB::table('product_unit_colors')
                                ->where('product_unit_id', $puRecordId)
                                ->where('color_id', (int) $v['color_id'])
                                ->whereNull('deleted_at')
                                ->first();
                            if (!$colorRec) {
                                throw new \RuntimeException("اللون غير موجود: product_unit_id={$puRecordId}, color_id={$v['color_id']}.");
                            }

                            $where = [
                                'warehouse_id'          => $warehouseId,
                                'product_unit_color_id' => $colorRec->id,
                            ];

                            // الرصيد القديم للـ variant نفسه (مش إجمالي المنتج)
                            $existing = DB::table('product_unit_color_warehouse')->where($where)->lockForUpdate()->first();
                            $variantSystem  = (int) ($existing->stock ?? 0);
                            $variantCounted = (int) $v['counted_stock'];

                            if ($existing) {
                                DB::table('product_unit_color_warehouse')->where('id', $existing->id)
                                    ->update(['stock' => $variantCounted, 'updated_at' => now()]);
                            } else {
                                DB::table('product_unit_color_warehouse')->insert($where + [
                                    'stock' => $variantCounted, 'created_at' => now(), 'updated_at' => now(),
                                ]);
                            }

                            // سجل جرد لكل variant (مربوط بالهيدر)
                            InventoryLog::create([
                                'inventory_count_id' => $count->id,
                                'warehouse_id'       => $warehouseId,
                                'product_id'         => $productId,
                                'product_unit_id'    => $puRecordId,
                                'color_id'           => (int) $v['color_id'],
                                'system_stock'       => $variantSystem,
                                'counted_stock'      => $variantCounted,
                                'difference'         => $variantCounted - $variantSystem,
                                'note'               => $request->note,
                            ]);

                            $processedProducts[] = [
                                'product_id'      => $productId,
                                'product_unit_id' => $puRecordId,
                                'color_id'        => (int) $v['color_id'],
                                'system_stock'    => $variantSystem,
                                'counted_stock'   => $variantCounted,
                                'difference'      => $variantCounted - $variantSystem,
                            ];
                        }

                        // مزامنة الإجماليات مرة واحدة بعد كل الـ variants
                        ProductUnitColorWarehouse::syncProduct($productId);
                        continue;
                    }

                    // منتج بدون ألوان → تحديث مباشر
                    $countedStock = (int) ($data['counted_stock'] ?? 0);

                    DB::table('product_warehouse')
                        ->where('id', $pw->id)
                        ->update([
                            'stock' => $countedStock,
                            'updated_at' => now(),
                        ]);

                    ProductUnitColorWarehouse::syncProduct($productId);

                    InventoryLog::create([
                        'inventory_count_id' => $count->id,
                        'warehouse_id'       => $warehouseId,
                        'product_id'         => $productId,
                        'system_stock'       => $systemStock,
                        'counted_stock'      => $countedStock,
                        'difference'         => $countedStock - $systemStock,
                        'note'               => $request->note,
                    ]);

                    $processedProducts[] = [
                        'product_id' => $productId,
                        'system_stock' => $systemStock,
                        'counted_stock' => $countedStock,
                        'difference' => $countedStock - $systemStock,
                    ];
                }

                if (empty($processedProducts)) {
                    throw new \RuntimeException(
                        'لم يتم معالجة أي منتج. تأكد من أن المنتجات موجودة فعلاً في المخزن المحدد.'
                    );
                }

                return ['count_id' => $count->id, 'items' => $processedProducts];
            });

            $items = $result['items'];

            $summary = [
                'total_products' => count(array_unique(array_column($items, 'product_id'))),
                'total_lines' => count($items),
                'total_system_stock' => array_sum(array_column($items, 'system_stock')),
                'total_counted_stock' => array_sum(array_column($items, 'counted_stock')),
                'total_difference' => array_sum(array_column($items, 'difference')),
            ];

            return response()->json([
                'result' => 'Success',
                'message' => 'تم تنفيذ الجرد بنجاح',
                'count_id' => $result['count_id'],
                'data' => $items,
                'summary' => $summary,
                'status' => 200,
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'result' => 'Error',
                'error' => $e->getMessage(),
                'message' => $e->getMessage(),
                'status' => 422,
            ], 422);
        }
    }

    public function indexProduct(Request $request)
    {
        try {
            $query = ProductWarehouse::with([
                'product.units.colors.warehouseStocks',
                'warehouse',
            ])->whereHas('product');

            if ($request->filled('filters.branch_id')) {
                $branchId = $request->input('filters.branch_id');
                $query->whereHas('warehouse', fn ($warehouseQuery) => $warehouseQuery->where('branch_id', $branchId));
            }

            if ($request->filled('filters.warehouse_name')) {
                $warehouseName = $request->input('filters.warehouse_name');
                $query->whereHas('warehouse', function ($q) use ($warehouseName) {
                    $q->where('name', 'LIKE', "%{$warehouseName}%");
                });
            }

            if ($request->filled('filters.warehouse_id')) {
                $query->where('warehouse_id', (int) $request->input('filters.warehouse_id'));
            }

            $query->orderByDesc('updated_at')->orderByDesc('id');

            $items = $query->get()->map(function ($item) use ($request) {
                if (!$item->product) {
                    return null;
                }

                $productData = (new ProductResource($item->product))->resolve($request);
                $productData['units'] = collect($productData['units'] ?? [])->map(function ($unit) use ($item) {
                    $unit['colors'] = collect($unit['colors'] ?? [])->map(function ($color) use ($item) {
                        $warehouseStock = collect($color['warehouseStocks'] ?? [])
                            ->firstWhere('warehouse_id', (int) $item->warehouse_id);
                        $color['warehouse_stock'] = (int) ($warehouseStock['stock'] ?? 0);
                        $color['stock'] = $color['warehouse_stock'];
                        return $color;
                    })->values()->all();
                    return $unit;
                })->values()->all();

                return [
                    'id' => $item->id,
                    'product_name' => $item->product?->name,
                    'product' => $item->product ? $productData : null,
                    'warehouse_name' => $item->warehouse?->name,
                    'stock' => $item->stock,
                    'cost' => $item->cost,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ];
            })->filter()->values();

            return response()->json([
                'data' => $items,
                'result' => 'Success',
                'message' => 'Product warehouse list fetched successfully',
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