<?php

namespace App\Http\Controllers;

use App\Helpers\JsonResponse;
use App\Http\Requests\WarehouseRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\WarehouseProductResource;
use App\Http\Resources\WarehouseResource;
use App\Interfaces\WarehouseRepositoryInterface;
use App\Models\InventoryLog;
use App\Models\Warehouse;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\ProductUnitColorWarehouse;   // ✅
class WarehouseController extends BaseController
{

    protected mixed $crudRepository;

    public function __construct(WarehouseRepositoryInterface $pattern)
    {
        $this->crudRepository = $pattern;
    }

    public function index()
    {
        try {
            $warehouse = WarehouseResource::collection($this->crudRepository->all(
                [],
                [],
                ['*']
            ));
            return $warehouse->additional(JsonResponse::success());
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function store(WarehouseRequest $request)
    {
        try {
            $warehouse = $this->crudRepository->create($request->validated());
            return new WarehouseResource($warehouse);
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function show(Warehouse $warehouse): ?\Illuminate\Http\JsonResponse
    {
        try {
            return JsonResponse::respondSuccess('Item Fetched Successfully', new WarehouseResource($warehouse));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


    public function update(WarehouseRequest $request, Warehouse $warehouse)
    {
        try {
            $this->crudRepository->update($request->validated(), $warehouse->id);
            activity()->performedOn($warehouse)->withProperties(['attributes' => $warehouse])->log('update');
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_UPDATED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


    public function destroy(Request $request): ?\Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecords('warehouses', $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function restore(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->restoreItem(Warehouse::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_RESTORED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }




    public function forceDelete(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecordsFinial(Warehouse::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_FORCE_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }


public function warehouseProducts(Warehouse $warehouse)
{
    $warehouseId = $warehouse->id;

    $products = $warehouse->products()
        ->withPivot('stock')
        ->wherePivot('stock', '>', 0)
        ->with([
            'units.colors.color',
            'units.colors.warehouseStocks' => function ($query) use ($warehouseId) {
                $query->where('warehouse_id', $warehouseId);
            },
        ])
        ->get();

    // إضافة warehouse_stock على كل لون
    $products->each(function ($product) use ($warehouseId) {
        $product->units->each(function ($unit) {
            $unit->colors->each(function ($color) {
                $ws = $color->warehouseStocks->first();
                $color->warehouse_stock = $ws ? (int) $ws->stock : 0;
            });
        });
    });

    return WarehouseProductResource::collection($products);
}






public function transfer(Request $request)
{
    $validated = $request->validate([
        'from_warehouse_id' => 'required|integer|exists:warehouses,id',
        'to_warehouse_id' => 'required|integer|different:from_warehouse_id|exists:warehouses,id',

        'products' => 'required|array|min:1',
        'products.*.product_id' => 'required|integer|exists:products,id',
        'products.*.product_unit_id' => 'nullable|integer|min:0',
        'products.*.color_id' => 'nullable|integer|min:0',
        'products.*.quantity' => 'required|integer|min:1',
        'products.*.note' => 'nullable|string|max:1000',
    ]);

    try {

        $result = DB::transaction(function () use ($validated) {

            $fromWarehouseId = (int) $validated['from_warehouse_id'];
            $toWarehouseId = (int) $validated['to_warehouse_id'];

            /*
            |--------------------------------------------------------------------------
            | 1. Lock warehouses
            |--------------------------------------------------------------------------
            */

            $warehouseIds = [$fromWarehouseId, $toWarehouseId];
            sort($warehouseIds);

            $lockedWarehouses = Warehouse::whereIn('id', $warehouseIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $fromWarehouse = $lockedWarehouses->get($fromWarehouseId);
            $toWarehouse = $lockedWarehouses->get($toWarehouseId);

            if (!$fromWarehouse || !$toWarehouse) {
                throw new \RuntimeException('المخزن غير موجود.');
            }

            /*
            |--------------------------------------------------------------------------
            | 2. Group products
            |--------------------------------------------------------------------------
            */

            $items = collect($validated['products']);

            $totals = $items
                ->groupBy('product_id')
                ->map(fn ($rows) => (int) $rows->sum('quantity'));

            /*
            |--------------------------------------------------------------------------
            | 3. Group variants
            |--------------------------------------------------------------------------
            */

            $variantTotals = [];
foreach ($items as $item) {
    $productId = (int) $item['product_id'];
    $unitId    = (int) ($item['product_unit_id'] ?? 0);
    $colorId   = (int) ($item['color_id'] ?? 0);
    $quantity  = (int) $item['quantity'];

    // منتج بدون مقاسات وألوان
    if ($unitId === 0 && $colorId === 0) {

        // ✅ جديد: امنع التحويل الجملة لو المنتج له ألوان
        $hasColors = DB::table('product_units as pu')
            ->join('product_unit_colors as puc', 'puc.product_unit_id', '=', 'pu.id')
            ->where('pu.product_id', $productId)
            ->whereNull('puc.deleted_at')
            ->exists();

        if ($hasColors) {
            throw new \RuntimeException(
                "المنتج {$productId} له مقاسات وألوان — لازم تحدد المقاس واللون قبل التحويل"
            );
        }

        continue;
    }

    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'from_warehouse_id' => 'required|integer|exists:warehouses,id',
            'to_warehouse_id' => 'required|integer|different:from_warehouse_id|exists:warehouses,id',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.product_unit_id' => 'nullable|integer|exists:product_units,id',
            'products.*.color_id' => 'nullable|integer|exists:colors,id',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.note' => 'nullable|string|max:1000',
        ]);

        try {
            $result = DB::transaction(function () use ($validated) {
                $fromWarehouse = Warehouse::whereKey($validated['from_warehouse_id'])->lockForUpdate()->firstOrFail();
                $toWarehouse = Warehouse::whereKey($validated['to_warehouse_id'])->lockForUpdate()->firstOrFail();
                $totals = collect($validated['products'])->groupBy('product_id')->map(fn ($items) => $items->sum('quantity'));
                $sourceRows = [];
                $variantMoves = [];
                $totalValue = 0.0;

                foreach ($totals as $productId => $quantity) {
                    $sourceRows[$productId] = DB::table('product_warehouse')
                        ->where('warehouse_id', $fromWarehouse->id)
                        ->where('product_id', $productId)
                        ->lockForUpdate()->first();
                    if (!$sourceRows[$productId] || (int) $sourceRows[$productId]->stock < (int) $quantity) {
                        throw new \RuntimeException('الكمية غير متاحة للتحويل في مخزن المصدر');
                    }
                    $product = DB::table('products')->where('id', $productId)->first();
                    $unitCost = (float) ($sourceRows[$productId]->cost ?? $product->cost ?? 0);
                    $totalValue += $unitCost * (int) $quantity;
                }

                $variantTotals[$variantKey]['quantity'] += $quantity;
            }

                    if ($unitId === 0 && $colorId === 0) {
                        continue;
                    }
                    if ($unitId === 0 || $colorId === 0) {
                        throw new \RuntimeException('يجب اختيار المقاس واللون معًا للتحويل');
                    }

                    $variant = DB::table('product_unit_colors as colors')
                        ->join('product_units as units', 'units.id', '=', 'colors.product_unit_id')
                        ->where('colors.product_unit_id', $unitId)
                        ->where('colors.color_id', $colorId)
                        ->where('units.product_id', $productId)
                        ->whereNull('colors.deleted_at')
                        ->whereNull('units.deleted_at')
                        ->select('colors.id as variant_id', 'colors.stock')
                        ->first();
                    if (!$variant) {
                        throw new \RuntimeException('المقاس واللون غير مرتبطين بهذا المنتج');
                    }

                    $sourceVariant = DB::table('warehouse_product_unit_colors')
                        ->where('warehouse_id', $fromWarehouse->id)
                        ->where('product_unit_color_id', $variant->variant_id)
                        ->lockForUpdate()->first();
                    if (!$sourceVariant || (int) $sourceVariant->stock < $quantity) {
                        throw new \RuntimeException('كمية المقاس واللون غير متاحة في مخزن المصدر');
                    }
                    $variantMoves[] = [
                        'variant_id' => (int) $variant->variant_id,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                    ];
                }

                foreach ($variantMoves as $move) {
                    $sourceVariant = DB::table('warehouse_product_unit_colors')
                        ->where('warehouse_id', $fromWarehouse->id)
                        ->where('product_unit_color_id', $move['variant_id'])
                        ->lockForUpdate()->first();
                    DB::table('warehouse_product_unit_colors')->whereKey($sourceVariant->id)->decrement('stock', $move['quantity']);

                    $destinationVariant = DB::table('warehouse_product_unit_colors')
                        ->where('warehouse_id', $toWarehouse->id)
                        ->where('product_unit_color_id', $move['variant_id'])
                        ->lockForUpdate()->first();
                    if ($destinationVariant) {
                        DB::table('warehouse_product_unit_colors')->whereKey($destinationVariant->id)->increment('stock', $move['quantity']);
                    } else {
                        DB::table('warehouse_product_unit_colors')->insert([
                            'warehouse_id' => $toWarehouse->id,
                            'product_unit_color_id' => $move['variant_id'],
                            'stock' => $move['quantity'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('product_unit_colors')->where('id', $move['variant_id'])->update([
                        'stock' => DB::raw('(SELECT COALESCE(SUM(stock), 0) FROM warehouse_product_unit_colors WHERE product_unit_color_id = ' . (int) $move['variant_id'] . ')'),
                        'updated_at' => now(),
                    ]);
                }

                foreach ($totals as $productId => $quantity) {
                    DB::table('product_warehouse')->whereKey($sourceRows[$productId]->id)->decrement('stock', $quantity);
                    $destinationStock = DB::table('product_warehouse')
                        ->where('warehouse_id', $toWarehouse->id)->where('product_id', $productId)
                        ->lockForUpdate()->first();
                    if ($destinationStock) {
                        DB::table('product_warehouse')->whereKey($destinationStock->id)->increment('stock', $quantity);
                        if ($destinationStock->cost === null && $sourceRows[$productId]->cost !== null) {
                            DB::table('product_warehouse')->whereKey($destinationStock->id)->update(['cost' => $sourceRows[$productId]->cost]);
                        }
                    } else {
                        DB::table('product_warehouse')->insert([
                            'warehouse_id' => $toWarehouse->id,
                            'product_id' => $productId,
                            'stock' => $quantity,
                            'cost' => $sourceRows[$productId]->cost,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    DB::table('products')->whereKey($productId)->update([
                        'stock' => DB::raw('(SELECT COALESCE(SUM(stock), 0) FROM product_warehouse WHERE product_id = ' . (int) $productId . ')'),
                        'updated_at' => now(),
                    ]);
                }

                $stockRows[$productId] = $source;

                $totalValue += $quantity * (float) ($source->cost ?? 0);
            }

            /*
            |--------------------------------------------------------------------------
            | 5. Validate variants (source color stock must already exist)
            |--------------------------------------------------------------------------
            */

            $variantRows = [];

            foreach ($variantTotals as $variantKey => $variant) {

                $productId = (int) $variant['product_id'];
                $unitId = (int) $variant['unit_id'];
                $colorId = (int) $variant['color_id'];
                $quantity = (int) $variant['quantity'];

                // التأكد من وجود المقاس
                $unit = DB::table('product_units')
                    ->where('id', $unitId)
                    ->where('product_id', $productId)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();

                if (!$unit) {
                    throw new \RuntimeException(
                        "المقاس غير موجود للمنتج {$productId}. Unit: {$unitId}"
                    );
                }

                // التأكد من وجود اللون
                $color = DB::table('product_unit_colors')
                    ->where('product_unit_id', $unit->id)
                    ->where('color_id', $colorId)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();

                if (!$color) {
                    throw new \RuntimeException(
                        "اللون غير موجود للمقاس المحدد. " .
                        "Product: {$productId}, Unit: {$unitId}, Color: {$colorId}"
                    );
                }

                // التأكد من وجود مخزون المنتج الأساسي
                $sourceProductStock = $stockRows[$productId];

                if ((float) $sourceProductStock->stock < $quantity) {
                    throw new \RuntimeException(
                        "المخزون الأساسي غير كافٍ للمنتج {$productId}."
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Find source color stock
                |--------------------------------------------------------------------------
                */

                $sourceColorStock = DB::table('product_unit_color_warehouse')
                    ->where('warehouse_id', $fromWarehouseId)
                    ->where('product_unit_color_id', $color->id)
                    ->lockForUpdate()
                    ->first();

                if (!$sourceColorStock) {
                    throw new \RuntimeException(
                        "رصيد اللون غير مسجل في المخزن المصدر. Product: {$productId}, Unit: {$unitId}, Color: {$colorId}"
                    );
                }

                // التحقق من كمية اللون
                if ((float) $sourceColorStock->stock < $quantity) {
                    throw new \RuntimeException(
                        "رصيد اللون غير كافٍ. " .
                        "Product: {$productId}, " .
                        "Available: {$sourceColorStock->stock}, " .
                        "Requested: {$quantity}"
                    );
                }

                $variantRows[$variantKey] = [
                    'product_id' => $productId,
                    'unit_id' => $unitId,
                    'color_id' => $colorId,
                    'color_record_id' => $color->id,
                    'source_stock_id' => $sourceColorStock->id,
                    'quantity' => $quantity,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | 6. Transfer main product stock
            |--------------------------------------------------------------------------
            */

            foreach ($totals as $productId => $quantity) {

                $source = $stockRows[$productId];

                // خصم من المخزن المصدر
                $affectedSource = DB::table('product_warehouse')
                    ->where('id', $source->id)
                    ->where('warehouse_id', $fromWarehouseId)
                    ->where('product_id', $productId)
                    ->where('stock', '>=', $quantity)
                    ->decrement('stock', $quantity);

                if ($affectedSource !== 1) {
                    throw new \RuntimeException(
                        "فشل خصم المخزون من المخزن المصدر. Product: {$productId}"
                    );
                }

                // البحث عن المنتج في المخزن المستلم
                $destinationStock = DB::table('product_warehouse')
                    ->where('warehouse_id', $toWarehouseId)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();

                if ($destinationStock) {

                    // المنتج موجود: زيادة رصيده
                    $affectedDestination = DB::table('product_warehouse')
                        ->where('id', $destinationStock->id)
                        ->increment('stock', $quantity);

                    if ($affectedDestination !== 1) {
                        throw new \RuntimeException(
                            "فشل زيادة مخزون المنتج في المخزن المستلم."
                        );
                    }

                    // تحديث التكلفة لو غير موجودة
                    if (
                        $destinationStock->cost === null &&
                        $source->cost !== null
                    ) {
                        DB::table('product_warehouse')
                            ->where('id', $destinationStock->id)
                            ->update([
                                'cost' => $source->cost,
                                'updated_at' => now(),
                            ]);
                    }

                } else {

                    // المنتج غير موجود: إنشاء سجل جديد لأول مرة
                    DB::table('product_warehouse')->insert([
                        'warehouse_id' => $toWarehouseId,
                        'product_id' => $productId,
                        'stock' => $quantity,
                        'cost' => $source->cost,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 7. Transfer variant stock
            |--------------------------------------------------------------------------
            */

            foreach ($variantRows as $variant) {

                $colorRecordId = $variant['color_record_id'];
                $quantity = (int) $variant['quantity'];

                // خصم اللون من المخزن المصدر
                $affectedSource = DB::table('product_unit_color_warehouse')
                    ->where('id', $variant['source_stock_id'])
                    ->where('warehouse_id', $fromWarehouseId)
                    ->where('product_unit_color_id', $colorRecordId)
                    ->where('stock', '>=', $quantity)
                    ->decrement('stock', $quantity);

                if ($affectedSource !== 1) {
                    throw new \RuntimeException(
                        "فشل خصم رصيد اللون من المخزن المصدر."
                    );
                }

                // البحث عن اللون في المخزن المستلم
                $destinationVariantStock = DB::table('product_unit_color_warehouse')
                    ->where('warehouse_id', $toWarehouseId)
                    ->where('product_unit_color_id', $colorRecordId)
                    ->lockForUpdate()
                    ->first();

                if ($destinationVariantStock) {

                    // اللون موجود: زيادة رصيده
                    $affectedDestination = DB::table('product_unit_color_warehouse')
                        ->where('id', $destinationVariantStock->id)
                        ->increment('stock', $quantity);

                    if ($affectedDestination !== 1) {
                        throw new \RuntimeException(
                            "فشل زيادة رصيد اللون في المخزن المستلم."
                        );
                    }

                } else {

                    // اللون غير موجود: إنشاء سجل جديد لأول مرة
                    DB::table('product_unit_color_warehouse')->insert([
                        'warehouse_id' => $toWarehouseId,
                        'product_unit_color_id' => $colorRecordId,
                        'stock' => $quantity,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // إبقاء الرقم الإجمالي على اللون متطابقًا مع مجموع المخازن
        // إبقاء الرقم الإجمالي على اللون متطابقًا مع مجموع المخازن
// ========== مزامنة كل الـ aggregates من المصدر الوحيد ==========
$affectedProductIds = collect(array_column($variantRows, 'product_id'))
    ->merge($totals->keys())
    ->unique()
    ->values()
    ->all();

foreach ($affectedProductIds as $pid) {
    ProductUnitColorWarehouse::syncProduct((int) $pid);
}

            /*
            |--------------------------------------------------------------------------
            | 8. Create warehouse transfer record
            |--------------------------------------------------------------------------
            */

            $warehouseTransfer = \App\Models\WarehouseTransfer::create([
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'inventory_value' => round($totalValue, 2),
                'created_by' => auth()->id(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | 9. Treasury handling
            |--------------------------------------------------------------------------
            */

            $treasuryFor = function ($branchId) {

                $query = \App\Models\Treasury::query();

                if ($branchId) {
                    $query->where('branch_id', $branchId);
                }

                return $query
                    ->orderByDesc('is_main')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first()
                    ?? \App\Models\Treasury::where('is_main', true)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();
            };

            $sourceTreasury = $treasuryFor($fromWarehouse->branch_id);
            $destinationTreasury = $treasuryFor($toWarehouse->branch_id);

            if (
                $totalValue > 0 &&
                $sourceTreasury &&
                $destinationTreasury &&
                $sourceTreasury->id !== $destinationTreasury->id
            ) {

                // نفس المعالجة المالية الموجودة في الكود الأصلي
                $sourceTreasury->increment('balance', $totalValue);

                $destinationTreasury->decrement('balance', $totalValue);

                \App\Models\TreasuryTransaction::create([
                    'treasury_id' => $sourceTreasury->id,
                    'type' => 'in',
                    'amount' => $totalValue,
                    'reference_type' => \App\Models\WarehouseTransfer::class,
                    'reference_id' => $warehouseTransfer->id,
                    'description' => 'قيمة مخزون محول إلى المخزن ' . $toWarehouse->name,
                    'created_by' => auth()->id(),
                ]);


                return ['value' => round($totalValue, 2), 'transfer_id' => $warehouseTransfer->id];
            });

            return response()->json([
                'result' => 'Success',
                'message' => 'تم تحويل المنتجات وتحديث رصيد المخزنين بنجاح',
                'inventory_value' => $result['value'],
                'transfer_id' => $result['transfer_id'],
                'status' => 200,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['result' => 'Error', 'error' => $e->getMessage(), 'message' => $e->getMessage()], 422);
        }
    }

    public function inventoryStore(Request $request)
    {
        DB::beginTransaction();

        try {

            $warehouse = Warehouse::findOrFail($request->warehouse_id);

            foreach ($request->products as $item) {

                $productId     = $item['product_id'];
                $countedStock  = $item['counted_stock'];

                // المنتج في المخزن
                $product = $warehouse->products()
                    ->where('products.id', $productId)
                    ->withPivot('stock')
                    ->first();

                if (!$product) {
                    continue; // أو throw لو تحب
                }

                $systemStock = $product->pivot->stock;
                $difference  = $countedStock - $systemStock;

                // تحديث الكمية للجرد
                $warehouse->products()->updateExistingPivot(
                    $productId,
                    [
                        'stock' => $countedStock
                    ]
                );

                // (اختياري) سجل حركة الجرد
                InventoryLog::create([
                    'warehouse_id'   => $warehouse->id,
                    'product_id'     => $productId,
                    'system_stock'   => $systemStock,
                    'counted_stock'  => $countedStock,
                    'difference'     => $difference,
                    'note'           => $request->note,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | 10. Return transfer result
            |--------------------------------------------------------------------------
            */

            return [
                'transfer_id' => $warehouseTransfer->id,
                'value' => round($totalValue, 2),
                'products_count' => $totals->count(),
                'total_quantity' => (int) $totals->sum(),
            ];

        }, 3);

        /*
        |--------------------------------------------------------------------------
        | 11. Verify stock after commit
        |--------------------------------------------------------------------------
        */

        $verification = [
            'database' => DB::connection()->getDatabaseName(),
            'from_warehouse_id' => (int) $validated['from_warehouse_id'],
            'to_warehouse_id' => (int) $validated['to_warehouse_id'],
            'products' => [],
        ];

        $verificationTotals = collect($validated['products'])
            ->groupBy('product_id');

        foreach ($verificationTotals as $productId => $productItems) {

            $verification['products'][$productId] = [
                'source_stock' => DB::table('product_warehouse')
                    ->where('product_id', $productId)
                    ->where('warehouse_id', $validated['from_warehouse_id'])
                    ->value('stock'),

                'destination_stock' => DB::table('product_warehouse')
                    ->where('product_id', $productId)
                    ->where('warehouse_id', $validated['to_warehouse_id'])
                    ->value('stock'),
            ];
        }

        \Log::info('WAREHOUSE TRANSFER AFTER COMMIT', $verification);

        /*
        |--------------------------------------------------------------------------
        | 12. Success response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'result' => 'Success',
            'message' => 'تم تحويل المنتجات بنجاح',
            'data' => $result,
            'verification' => $verification,
            'inventory_value' => $result['value'],
            'status' => 200,
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {

        throw $e;

    } catch (\Throwable $e) {

        \Log::error('WAREHOUSE TRANSFER ERROR', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'payload' => $request->except(['password', 'token']),
        ]);

        return response()->json([
            'result' => 'Error',
            'error' => $e->getMessage(),
            'message' => $e->getMessage(),
            'status' => 422,
        ], 422);
    }
}



public function inventoryStore(Request $request)
{
    $request->validate([
        'warehouse_id' => 'required|integer|exists:warehouses,id',
        'products' => 'required|array|min:1',
        'products.*.product_id' => 'required|integer|exists:products,id',
        'products.*.counted_stock' => 'nullable|integer|min:0',
        'products.*.variants' => 'nullable|array',
        'products.*.variants.*.product_unit_id' => 'required_with:products.*.variants|integer',
        'products.*.variants.*.color_id' => 'required_with:products.*.variants|integer',
        'products.*.variants.*.counted_stock' => 'required_with:products.*.variants|integer|min:0',
        'note' => 'nullable|string',
    ]);

    try {
        DB::transaction(function () use ($request) {
            $warehouseId = (int) $request->warehouse_id;

            foreach ($request->products as $item) {
                $productId = (int) $item['product_id'];

                $pw = DB::table('product_warehouse')
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();

                if (!$pw) continue;

                $systemStock = (int) $pw->stock;

                if (!empty($item['variants'])) {
                    // 1) حدّث رصيد كل لون في المخزن
                    foreach ($item['variants'] as $v) {
                        $colorRec = DB::table('product_unit_colors')
                            ->where('product_unit_id', $v['product_unit_id'])
                            ->where('color_id', $v['color_id'])
                            ->whereNull('deleted_at')
                            ->first();

                        if (!$colorRec) continue;

                        DB::table('product_unit_color_warehouse')->updateOrInsert(
                            [
                                'warehouse_id'          => $warehouseId,
                                'product_unit_color_id' => $colorRec->id,
                            ],
                            [
                                'stock'      => (int) $v['counted_stock'],
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                    }

                    // 2) ✅ مزامنة كاملة (تحدّث colors + product_warehouse + products.stock)
                    ProductUnitColorWarehouse::syncProduct($productId);

                    // 3) اقرأ الرصيد النهائي من product_warehouse (بعد المزامنة)
                    $countedStock = (int) DB::table('product_warehouse')
                        ->where('warehouse_id', $warehouseId)
                        ->where('product_id', $productId)
                        ->value('stock') ?? 0;
                } else {
                    // منتج بدون ألوان
                    $countedStock = (int) $item['counted_stock'];

                    DB::table('product_warehouse')
                        ->where('id', $pw->id)
                        ->update([
                            'stock'      => $countedStock,
                            'updated_at' => now(),
                        ]);

                    // ✅ مزامنة products.stock
                    ProductUnitColorWarehouse::syncProduct($productId);
                }

                InventoryLog::create([
                    'warehouse_id'  => $warehouseId,
                    'product_id'    => $productId,
                    'system_stock'  => $systemStock,
                    'counted_stock' => $countedStock,
                    'difference'    => $countedStock - $systemStock,
                    'note'          => $request->note,
                ]);
            }
        });

        return response()->json(['message' => 'تم تنفيذ الجرد بنجاح']);
    } catch (\Throwable $e) {
        return response()->json(['error' => $e->getMessage()], 422);
    }
}

 public function updateCountedStock(Request $request, InventoryLog $inventoryLog)
{
    $request->validate([
        'counted_stock' => 'required|integer|min:0',
        'note' => 'nullable|string',
    ]);

    $hasColors = DB::table('product_unit_colors as c')
        ->join('product_units as u', 'u.id', '=', 'c.product_unit_id')
        ->where('u.product_id', $inventoryLog->product_id)
        ->whereNull('c.deleted_at')
        ->exists();

    if ($hasColors) {
        return response()->json([
            'result' => 'Error',
            'message' => 'المنتج له ألوان ومقاسات، أعد الجرد على مستوى اللون.',
        ], 422);
    }

    DB::transaction(function () use ($request, $inventoryLog) {
        DB::table('product_warehouse')
            ->where('warehouse_id', $inventoryLog->warehouse_id)
            ->where('product_id', $inventoryLog->product_id)
            ->update(['stock' => $request->counted_stock, 'updated_at' => now()]);
    ProductUnitColorWarehouse::syncProduct($inventoryLog->product_id);
        $inventoryLog->update([
            'counted_stock' => $request->counted_stock,
            'note' => $request->note,
            'difference' => $request->counted_stock - $inventoryLog->system_stock,
        ]);
    });

    return response()->json([
        'result' => 'Success',
        'message' => 'Inventory log updated successfully',
        'data' => $inventoryLog->fresh(),
    ]);
}


}