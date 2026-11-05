<?php

namespace App\Http\Controllers;

use App\Helpers\JsonResponse;
use App\Http\Resources\InventoryLogResource;
use App\Http\Resources\ProductResource;
use App\Interfaces\InventoryRepositoryInterface;
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
                        // 2a) تحديث رصيد كل لون في المخزن
                        foreach ($data['variants'] as $v) {
                            // تحويل unit_id (نوع المقاس) → product_unit_id (سجل المقاس)
                            $puRecordId = $v['product_unit_id'] ?? null;

                            if (!$puRecordId && !empty($v['unit_id'])) {
                                $puRecordId = DB::table('product_units')
                                    ->where('product_id', $productId)
                                    ->where('unit_id', (int) $v['unit_id'])
                                    ->whereNull('deleted_at')
                                    ->value('id');
                            }

                            if (!$puRecordId) {
                                throw new \RuntimeException(
                                    "لم يتم العثور على سجل المقاس للمنتج [{$productId}] (unit_id=" . ($v['unit_id'] ?? 'null') . ")."
                                );
                            }

                            $colorRec = DB::table('product_unit_colors')
                                ->where('product_unit_id', $puRecordId)
                                ->where('color_id', (int) $v['color_id'])
                                ->whereNull('deleted_at')
                                ->first();

                            if (!$colorRec) {
                                throw new \RuntimeException(
                                    "اللون غير موجود للمنتج [{$productId}]: product_unit_id={$puRecordId}, color_id={$v['color_id']}."
                                );
                            }

                            DB::table('product_unit_color_warehouse')->updateOrInsert(
                                [
                                    'warehouse_id' => $warehouseId,
                                    'product_unit_color_id' => $colorRec->id,
                                ],
                                [
                                    'stock' => (int) $v['counted_stock'],
                                    'updated_at' => now(),
                                    'created_at' => now(),
                                ]
                            );
                        }

                        // 2b) مزامنة الـ aggregates (product_warehouse + products + colors)
                        ProductUnitColorWarehouse::syncProduct($productId);

                        // 2c) قراءة الرصيد النهائي بعد المزامنة
                        $countedStock = (int) (DB::table('product_warehouse')
                            ->where('warehouse_id', $warehouseId)
                            ->where('product_id', $productId)
                            ->value('stock') ?? 0);
                    } else {
                        // 2d) منتج بدون ألوان → تحديث مباشر
                        $countedStock = (int) ($data['counted_stock'] ?? 0);

                        DB::table('product_warehouse')
                            ->where('id', $pw->id)
                            ->update([
                                'stock' => $countedStock,
                                'updated_at' => now(),
                            ]);

                        ProductUnitColorWarehouse::syncProduct($productId);
                    }

                    // 3) سجل الجرد
                    InventoryLog::create([
                        'warehouse_id' => $warehouseId,
                        'product_id' => $productId,
                        'system_stock' => $systemStock,
                        'counted_stock' => $countedStock,
                        'difference' => $countedStock - $systemStock,
                        'note' => $request->note,
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

                return $processedProducts;
            });

            $summary = [
                'total_products' => count($result),
                'total_system_stock' => array_sum(array_column($result, 'system_stock')),
                'total_counted_stock' => array_sum(array_column($result, 'counted_stock')),
                'total_difference' => array_sum(array_column($result, 'difference')),
            ];

            return response()->json([
                'result' => 'Success',
                'message' => 'تم تنفيذ الجرد بنجاح',
                'data' => $result,
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

public function show(InventoryLog $inventoryLog)
{
    $inventoryLog->load(['warehouse', 'product']);

    $product     = $inventoryLog->product;
    $warehouseId = (int) $inventoryLog->warehouse_id;

    // ============ 1) بناء بيانات المنتج بنفس شكل /api/product/{id}?warehouse_id={wid} ============
    $productData = null;

    if ($product) {
        $productData = [
            'id'      => $product->id,
            'name'    => $product->name,
            'name_ar' => $product->name_ar ?? null,
            'sku'     => $product->sku,
            'barcode' => $product->barcode ?? null,
            'units'   => [],
        ];

        // ----- المقاسات -----
        $units = DB::table('product_units')
            ->where('product_id', $product->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'unit_id']);

        // ----- أسماء المقاسات من جدول units (مع fallback) -----
        $unitNames = [];
        $unitIds = $units->pluck('unit_id')->filter()->unique()->values()->all();
        if (!empty($unitIds)) {
            $unitRecords = DB::table('units')->whereIn('id', $unitIds)->get();
            foreach ($unitRecords as $u) {
                $arr = (array) $u;
                $unitNames[$u->id] = $arr['name']
                    ?? $arr['name_ar']
                    ?? $arr['name_en']
                    ?? $arr['unit_name']
                    ?? $arr['code']
                    ?? null;
            }
        }

        // ----- الألوان (مع fallback أوسع) -----
        $colorRecords = DB::table('colors')->get()->keyBy('id');

        // ----- لكل مقاس → ألوانه + مخزونها في المخزن المحدد -----
        foreach ($units as $unit) {
            $unitColors = DB::table('product_unit_colors')
                ->where('product_unit_id', $unit->id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get(['id', 'color_id']);

            $colorsData = [];
            foreach ($unitColors as $colorRec) {
                $warehouseStock = (int) (DB::table('product_unit_color_warehouse')
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_unit_color_id', $colorRec->id)
                    ->value('stock') ?? 0);

                $colorMeta = $colorRecords->get($colorRec->color_id);
                $colorArr  = $colorMeta ? (array) $colorMeta : [];

                $colorsData[] = [
                    'id'              => $colorRec->id,
                    'color_id'        => $colorRec->color_id,
                    'color'           => $colorArr['name']
                        ?? $colorArr['name_ar']
                        ?? $colorArr['name_en']
                        ?? $colorArr['color']
                        ?? null,
                    'hex_code'        => $colorArr['hex_code']
                        ?? $colorArr['code']
                        ?? $colorArr['hex']
                        ?? null,
                    'stock'           => $warehouseStock,
                    'warehouse_stock' => $warehouseStock,
                ];
            }

            $productData['units'][] = [
                'id'        => $unit->id,
                'unit_id'   => $unit->unit_id,
                'unit_name' => $unitNames[$unit->unit_id] ?? null,
                'colors'    => $colorsData,
            ];
        }
    }

    // ============ 2) الرصيد الحالي للمنتج في المخزن ============
    $currentProductStock = (int) (DB::table('product_warehouse')
        ->where('warehouse_id', $warehouseId)
        ->where('product_id', $inventoryLog->product_id)
        ->value('stock') ?? 0);

    // ============ 3) الـ response ============
    return response()->json([
        'result'  => 'Success',
        'message' => 'Inventory log details fetched successfully',
        'data'    => [
            'id'        => $inventoryLog->id,
            'warehouse' => $inventoryLog->warehouse ? [
                'id'      => $inventoryLog->warehouse->id,
                'name'    => $inventoryLog->warehouse->name,
                'name_ar' => $inventoryLog->warehouse->name_ar ?? null,
            ] : null,
            'product'               => $productData,  // ← نفس شكل /api/product/{id}?warehouse_id={wid}
            'system_stock'          => $inventoryLog->system_stock,
            'counted_stock'         => $inventoryLog->counted_stock,
            'difference'            => $inventoryLog->difference,
            'note'                  => $inventoryLog->note,
            'created_at'            => $inventoryLog->created_at,
            'current_product_stock' => $currentProductStock,
        ],
        'status' => 200,
    ]);
}
public function updateCountedStock(Request $request, InventoryLog $inventoryLog)
{
    $request->validate([
        'counted_stock' => 'nullable|integer|min:0',
        'note'          => 'nullable|string',
        'variants'      => 'nullable|array',
        'variants.*.product_unit_id' => 'nullable|integer',
        'variants.*.unit_id'         => 'nullable|integer',
        'variants.*.color_id'        => 'required_with:variants|integer',
        'variants.*.counted_stock'   => 'required_with:variants|integer|min:0',
    ]);

    try {
        DB::transaction(function () use ($request, $inventoryLog) {
            $warehouseId = (int) $inventoryLog->warehouse_id;
            $productId   = (int) $inventoryLog->product_id;

            // هل المنتج عنده ألوان/مقاسات؟
            $hasColors = DB::table('product_unit_colors as c')
                ->join('product_units as u', 'u.id', '=', 'c.product_unit_id')
                ->where('u.product_id', $productId)
                ->whereNull('c.deleted_at')
                ->exists();

            if ($hasColors) {
                if (empty($request->variants)) {
                    throw new \RuntimeException(
                        'المنتج له ألوان ومقاسات، لازم ترسل تفاصيل كل لون (variants).'
                    );
                }

                // تحديث رصيد كل لون في المخزن
                foreach ($request->variants as $v) {
                    $puRecordId = $v['product_unit_id'] ?? null;

                    if (!$puRecordId && !empty($v['unit_id'])) {
                        $puRecordId = DB::table('product_units')
                            ->where('product_id', $productId)
                            ->where('unit_id', (int) $v['unit_id'])
                            ->whereNull('deleted_at')
                            ->value('id');
                    }

                    if (!$puRecordId) {
                        throw new \RuntimeException(
                            "لم يتم العثور على سجل المقاس للمنتج [{$productId}] (unit_id=" . ($v['unit_id'] ?? 'null') . ")."
                        );
                    }

                    $colorRec = DB::table('product_unit_colors')
                        ->where('product_unit_id', $puRecordId)
                        ->where('color_id', (int) $v['color_id'])
                        ->whereNull('deleted_at')
                        ->first();

                    if (!$colorRec) {
                        throw new \RuntimeException(
                            "اللون غير موجود للمنتج [{$productId}]: product_unit_id={$puRecordId}, color_id={$v['color_id']}."
                        );
                    }

                    DB::table('product_unit_color_warehouse')->updateOrInsert(
                        [
                            'warehouse_id' => $warehouseId,
                            'product_unit_color_id' => $colorRec->id,
                        ],
                        [
                            'stock'      => (int) $v['counted_stock'],
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }

                ProductUnitColorWarehouse::syncProduct($productId);

                $countedStock = (int) (DB::table('product_warehouse')
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->value('stock') ?? 0);
            } else {
                $countedStock = (int) ($request->counted_stock ?? 0);

                DB::table('product_warehouse')
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->update([
                        'stock'      => $countedStock,
                        'updated_at' => now(),
                    ]);

                ProductUnitColorWarehouse::syncProduct($productId);
            }

            $inventoryLog->update([
                'counted_stock' => $countedStock,
                'note'          => $request->note ?? $inventoryLog->note,
                'difference'    => $countedStock - $inventoryLog->system_stock,
            ]);
        });

        return response()->json([
            'result'  => 'Success',
            'message' => 'Inventory log updated successfully',
            'data'    => $inventoryLog->fresh(),
            'status'  => 200,
        ]);
    } catch (ValidationException $e) {
        throw $e;
    } catch (\Throwable $e) {
        return response()->json([
            'result'  => 'Error',
            'message' => $e->getMessage(),
            'status'  => 422,
        ], 422);
    }
}
}