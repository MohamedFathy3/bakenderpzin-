<?php

namespace App\Http\Controllers;
use App\Models\ProductWarehouse;
use App\Helpers\JsonResponse;
use App\Http\Requests\WarehouseRequest;
use App\Http\Resources\WarehouseProductResource;
use App\Http\Resources\WarehouseResource;
use App\Interfaces\WarehouseRepositoryInterface;
use App\Models\InventoryLog;
use App\Models\ProductUnitColorWarehouse;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WarehouseController extends BaseController
{
    protected mixed $crudRepository;

    public function __construct(WarehouseRepositoryInterface $pattern)
    {
        $this->crudRepository = $pattern;
    }

    /**
     * ✅ FIX #1: index() بيدعم الفلاتر (active, branch_id) + ترتيب + pagination
     */
    public function index(Request $request)
    {
        try {
            $query = Warehouse::query();

            if ($request->has('filters.active') && $request->input('filters.active') !== null) {
                $query->where('active', (bool) $request->input('filters.active'));
            }

            if ($request->filled('filters.branch_id')) {
                $query->where('branch_id', (int) $request->input('filters.branch_id'));
            }

            if ($request->filled('filters.name')) {
                $name = $request->input('filters.name');
                $query->where(function ($q) use ($name) {
                    $q->where('name', 'LIKE', "%{$name}%")
                      ->orWhere('name_ar', 'LIKE', "%{$name}%");
                });
            }

            $orderBy = $request->input('orderBy', 'id');
            $orderDir = $request->input('orderByDirection', 'asc');
            $query->orderBy($orderBy, $orderDir);

            $rows = $request->boolean('paginate', false)
                ? $query->paginate((int) $request->input('perPage', 15))
                : $query->get();

            return WarehouseResource::collection($rows)->additional(JsonResponse::success());
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

    public function restore(Request $request): ?\Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->restoreItem(Warehouse::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_RESTORED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    public function forceDelete(Request $request): ?\Illuminate\Http\JsonResponse
    {
        try {
            $this->crudRepository->deleteRecordsFinial(Warehouse::class, $request['items']);
            return JsonResponse::respondSuccess(trans(JsonResponse::MSG_FORCE_DELETED_SUCCESSFULLY));
        } catch (Exception $e) {
            return JsonResponse::respondError($e->getMessage());
        }
    }

    /**
     * ✅ FIX #3: warehouseProducts - stock على مستوى المنتج =
     *    - pivot.stock لو مفيش variants
     *    - مجموع warehouse_stock للألوان لو فيه variants
     *    + color.warehouse_stock و color.stock = نفس الرقم
     */
    public function warehouseProducts(Warehouse $warehouse)
    {
        $warehouseId = $warehouse->id;

        $products = $warehouse->products()
            ->with([
                'units.colors.color',
                'units.colors.warehouseStocks' => function ($query) use ($warehouseId) {
                    $query->where('warehouse_id', $warehouseId);
                },
            ])
            ->withPivot('stock')
            ->wherePivot('stock', '>', 0)
            ->get();

        $products->each(function ($product) use ($warehouseId) {
            $variantSum = 0;
            $hasUnits = $product->units->isNotEmpty();

            $product->units->each(function ($unit) use ($warehouseId, &$variantSum) {
                $unit->colors->each(function ($color) use ($warehouseId, &$variantSum) {
                    $ws = $color->warehouseStocks->first();
                    $color->warehouse_stock = $ws ? (int) $ws->stock : 0;
                    // ✅ stock على مستوى اللون = warehouse_stock (مش الإجمالي)
                    $color->stock = $color->warehouse_stock;
                    $variantSum += $color->warehouse_stock;
                });
            });

            // ✅ stock على مستوى المنتج = متطابق مع computeEffectiveStock في الفرونت
            $product->stock = $hasUnits
                ? $variantSum
                : (int) ($product->pivot->stock ?? 0);

            // للحفاظ على pivot_stock الأصلي لو احتاجه العميل
            $product->warehouse_stock = (int) ($product->pivot->stock ?? 0);
        });

        return WarehouseProductResource::collection($products);
    }

    /**
     * ✅ FIX #3: indexProduct - نفس المنطق في warehouseProducts
     *    + إضافة warehouse_id و product_id صريحة
     */
    public function indexProduct(Request $request)
    {
        try {
            $query = ProductWarehouse::with([
                'product.units.colors.warehouseStocks',
                'warehouse',
            ])->whereHas('product');

            if ($request->filled('filters.branch_id')) {
                $branchId = $request->input('filters.branch_id');
                $query->whereHas('warehouse', fn ($wq) => $wq->where('branch_id', $branchId));
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

            if ($request->filled('filters.barcode')) {
                $barcode = $request->input('filters.barcode');
                $query->whereHas('product', function ($q) use ($barcode) {
                    $q->where('barcode', 'LIKE', "%{$barcode}%");
                });
            }

            if ($request->filled('filters.product_name')) {
                $name = $request->input('filters.product_name');
                $query->whereHas('product', function ($q) use ($name) {
                    $q->where('name', 'LIKE', "%{$name}%")
                      ->orWhere('name_ar', 'LIKE', "%{$name}%");
                });
            }

            $query->orderByDesc('updated_at')->orderByDesc('id');

            $items = $query->get()->map(function ($item) use ($request) {
                if (!$item->product) return null;

                $productData = (new \App\Http\Resources\ProductResource($item->product))->resolve($request);

                $variantSum = 0;
                $hasUnits = !empty($productData['units']);

                $productData['units'] = collect($productData['units'] ?? [])->map(function ($unit) use ($item, &$variantSum) {
                    $unit['colors'] = collect($unit['colors'] ?? [])->map(function ($color) use ($item, &$variantSum) {
                        $warehouseStock = collect($color['warehouseStocks'] ?? [])
                            ->firstWhere('warehouse_id', (int) $item->warehouse_id);

                        $color['warehouse_stock'] = (int) ($warehouseStock['stock'] ?? 0);
                        // ✅ stock على مستوى اللون = نفس الرقم
                        $color['stock'] = $color['warehouse_stock'];

                        $variantSum += $color['warehouse_stock'];
                        return $color;
                    })->values()->all();
                    return $unit;
                })->values()->all();

                // ✅ stock على مستوى المنتج = متطابق مع computeEffectiveStock
                $displayStock = $hasUnits ? $variantSum : (int) $item->stock;

                return [
                    'id'              => $item->id,
                    'product_id'      => $item->product->id,
                    'product_name'    => $item->product?->name,
                    'name'            => $item->product?->name,
                    'barcode'         => $item->product?->barcode,
                    'product'         => $item->product ? $productData : null,
                    'warehouse_id'    => $item->warehouse_id,
                    'warehouse_name'  => $item->warehouse?->name,
                    'stock'           => $displayStock,
                    'pivot_stock'     => (int) $item->stock,
                    'cost'            => $item->cost,
                    'created_at'      => $item->created_at,
                    'updated_at'      => $item->updated_at,
                ];
            })->filter()->values();

            return response()->json([
                'data'   => $items,
                'result' => 'Success',
                'message'=> 'Product warehouse list fetched successfully',
                'status' => 200,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'result'  => 'Error',
                'message' => $e->getMessage(),
                'status'  => 500,
            ], 500);
        }
    }

    /**
     * ✅ FIX #2: transfer - إصلاح حساب unit_cost و total_cost
     *    يستخدم نفس fallback زي inventory_value (source.cost → products.cost → 0)
     */
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
                $fromId = (int) $validated['from_warehouse_id'];
                $toId = (int) $validated['to_warehouse_id'];

                // 1) قفل المخزنين بترتيب ثابت
                $ids = [$fromId, $toId];
                sort($ids);

                $locked = Warehouse::whereIn('id', $ids)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $fromWarehouse = $locked->get($fromId);
                $toWarehouse = $locked->get($toId);

                if (!$fromWarehouse || !$toWarehouse) {
                    throw new \RuntimeException('المخزن غير موجود.');
                }

                // 2) تجميع الكميات
                $items = collect($validated['products']);

                $totals = $items
                    ->groupBy('product_id')
                    ->map(fn ($rows) => (int) $rows->sum('quantity'));

                $variantTotals = [];

                foreach ($items as $item) {
                    $productId = (int) $item['product_id'];
                    $unitId = (int) ($item['product_unit_id'] ?? 0);
                    $colorId = (int) ($item['color_id'] ?? 0);
                    $quantity = (int) $item['quantity'];

                    if ($unitId === 0 && $colorId === 0) {
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

                    if ($unitId === 0 || $colorId === 0) {
                        throw new \RuntimeException('يجب اختيار المقاس واللون معًا للتحويل');
                    }

                    $key = "{$productId}-{$unitId}-{$colorId}";

                    if (!isset($variantTotals[$key])) {
                        $variantTotals[$key] = [
                            'product_id' => $productId,
                            'unit_id' => $unitId,
                            'color_id' => $colorId,
                            'quantity' => 0,
                            'note' => null,
                        ];
                    }
                    $variantTotals[$key]['quantity'] += $quantity;
                    if (!empty($item['note'])) {
                        $variantTotals[$key]['note'] = $item['note'];
                    }
                }

                // 3) التحقق من أرصدة المنتجات + حساب التكلفة الموحد
                $stockRows = [];
                $productCosts = []; // ✅ FIX #2: cache التكلفة لكل منتج
                $totalValue = 0.0;

                foreach ($totals as $productId => $quantity) {
                    $source = DB::table('product_warehouse')
                        ->where('warehouse_id', $fromId)
                        ->where('product_id', $productId)
                        ->lockForUpdate()
                        ->first();

                    if (!$source || (int) $source->stock < $quantity) {
                        throw new \RuntimeException("الكمية غير متاحة للتحويل في مخزن المصدر. Product: {$productId}");
                    }

                    // ✅ FIX #2: نفس الـ fallback في كل مكان
                    $unitCost = $source->cost;
                    if ($unitCost === null) {
                        $unitCost = DB::table('products')->where('id', $productId)->value('cost');
                    }
                    $unitCost = (float) ($unitCost ?? 0);

                    $stockRows[$productId] = $source;
                    $productCosts[$productId] = $unitCost; // cache
                    $totalValue += $quantity * $unitCost;
                }

                // 4) التحقق من أرصدة الـ variants
                $variantRows = [];

                foreach ($variantTotals as $key => $variant) {
                    $productId = $variant['product_id'];
                    $unitId = $variant['unit_id'];
                    $colorId = $variant['color_id'];
                    $quantity = $variant['quantity'];

                    $unit = DB::table('product_units')
                        ->where('id', $unitId)
                        ->where('product_id', $productId)
                        ->whereNull('deleted_at')
                        ->first();

                    if (!$unit) {
                        throw new \RuntimeException("المقاس غير موجود للمنتج {$productId}. Unit: {$unitId}");
                    }

                    $color = DB::table('product_unit_colors')
                        ->where('product_unit_id', $unit->id)
                        ->where('color_id', $colorId)
                        ->whereNull('deleted_at')
                        ->first();

                    if (!$color) {
                        throw new \RuntimeException(
                            "اللون غير موجود للمقاس المحدد. Product: {$productId}, Unit: {$unitId}, Color: {$colorId}"
                        );
                    }

                    $sourceColorStock = DB::table('product_unit_color_warehouse')
                        ->where('warehouse_id', $fromId)
                        ->where('product_unit_color_id', $color->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$sourceColorStock) {
                        throw new \RuntimeException(
                            "رصيد اللون غير مسجل في المخزن المصدر. Product: {$productId}, Unit: {$unitId}, Color: {$colorId}"
                        );
                    }

                    if ((int) $sourceColorStock->stock < $quantity) {
                        throw new \RuntimeException(
                            "رصيد اللون غير كافٍ. Product: {$productId}, Available: {$sourceColorStock->stock}, Requested: {$quantity}"
                        );
                    }

                    $variantRows[$key] = [
                        'product_id'        => $productId,
                        'unit_id'           => (int) $unit->id,
                        'color_id'          => $colorId,
                        'color_record_id'   => $color->id,
                        'source_stock_id'   => $sourceColorStock->id,
                        'source_before'     => (int) $sourceColorStock->stock,
                        'quantity'          => $quantity,
                        'note'              => $variant['note'],
                    ];
                }

                // 5) تحويل أرصدة المنتجات
                $productDestSnapshots = [];
                foreach ($totals as $productId => $quantity) {
                    $source = $stockRows[$productId];
                    $sourceBefore = (int) $source->stock;

                    $affected = DB::table('product_warehouse')
                        ->where('id', $source->id)
                        ->where('stock', '>=', $quantity)
                        ->decrement('stock', $quantity);

                    if ($affected !== 1) {
                        throw new \RuntimeException("فشل خصم المخزون من المخزن المصدر. Product: {$productId}");
                    }
                    DB::table('product_warehouse')
                        ->where('id', $source->id)
                        ->update(['updated_at' => now()]);

                    $destination = DB::table('product_warehouse')
                        ->where('warehouse_id', $toId)
                        ->where('product_id', $productId)
                        ->lockForUpdate()
                        ->first();

                    $destBefore = $destination ? (int) $destination->stock : 0;

                    if ($destination) {
                        DB::table('product_warehouse')
                            ->where('id', $destination->id)
                            ->increment('stock', $quantity);
                        DB::table('product_warehouse')
                            ->where('id', $destination->id)
                            ->update(['updated_at' => now()]);

                        if ($destination->cost === null && $source->cost !== null) {
                            DB::table('product_warehouse')
                                ->where('id', $destination->id)
                                ->update(['cost' => $source->cost, 'updated_at' => now()]);
                        }
                    } else {
                        DB::table('product_warehouse')->insert([
                            'warehouse_id' => $toId,
                            'product_id' => $productId,
                            'stock' => $quantity,
                            'cost' => $source->cost,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $productDestSnapshots[$productId] = [
                        'source_before'      => $sourceBefore,
                        'source_after'       => $sourceBefore - $quantity,
                        'destination_before' => $destBefore,
                        'destination_after'  => $destBefore + $quantity,
                    ];
                }

                // 6) تحويل أرصدة الـ variants
                $transferItemsData = [];

                foreach ($variantRows as $variant) {
                    $colorRecordId = $variant['color_record_id'];
                    $quantity = $variant['quantity'];
                    $sourceBefore = $variant['source_before'];

                    $affected = DB::table('product_unit_color_warehouse')
                        ->where('id', $variant['source_stock_id'])
                        ->where('stock', '>=', $quantity)
                        ->decrement('stock', $quantity);

                    if ($affected !== 1) {
                        throw new \RuntimeException('فشل خصم رصيد اللون من المخزن المصدر.');
                    }

                    $destination = DB::table('product_unit_color_warehouse')
                        ->where('warehouse_id', $toId)
                        ->where('product_unit_color_id', $colorRecordId)
                        ->lockForUpdate()
                        ->first();

                    $destBefore = $destination ? (int) $destination->stock : 0;

                    if ($destination) {
                        DB::table('product_unit_color_warehouse')
                            ->where('id', $destination->id)
                            ->increment('stock', $quantity);
                    } else {
                        DB::table('product_unit_color_warehouse')->insert([
                            'warehouse_id' => $toId,
                            'product_unit_color_id' => $colorRecordId,
                            'stock' => $quantity,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    // ✅ FIX #2: استخدام نفس التكلفة المخزنة
                    $productCost = $productCosts[$variant['product_id']] ?? 0.0;

                    $transferItemsData[] = [
                        'product_id'         => $variant['product_id'],
                        'product_unit_id'    => $variant['unit_id'],
                        'color_id'           => $variant['color_id'],
                        'quantity'           => $quantity,
                        'source_before'      => $sourceBefore,
                        'source_after'       => $sourceBefore - $quantity,
                        'destination_before' => $destBefore,
                        'destination_after'  => $destBefore + $quantity,
                        'unit_cost'          => $productCost,
                        'total_cost'         => round($quantity * $productCost, 2),
                        'note'               => $variant['note'],
                    ];
                }

                // 7) مزامنة الـ aggregates
                $affectedProductIds = collect(array_column($variantRows, 'product_id'))
                    ->merge($totals->keys())
                    ->unique()
                    ->values();

                foreach ($affectedProductIds as $pid) {
                    ProductUnitColorWarehouse::syncProduct((int) $pid);
                }

                DB::table('product_warehouse')
                    ->whereIn('product_id', $affectedProductIds->all())
                    ->whereIn('warehouse_id', [$fromId, $toId])
                    ->update(['updated_at' => now()]);

                // 8) رقم التحويل اليومي WHS-DDMMYYYY-N
                $dateKey = now()->format('dmY');

                DB::table('warehouse_transfer_sequences')->insertOrIgnore([
                    'date_key'    => $dateKey,
                    'last_number' => 0,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);

                $sequenceRow = DB::table('warehouse_transfer_sequences')
                    ->where('date_key', $dateKey)
                    ->lockForUpdate()
                    ->first();

                $nextSequence = ((int) ($sequenceRow->last_number ?? 0)) + 1;

                DB::table('warehouse_transfer_sequences')
                    ->where('date_key', $dateKey)
                    ->update([
                        'last_number' => $nextSequence,
                        'updated_at'  => now(),
                    ]);

                $warehouseTransfer = WarehouseTransfer::create([
                    'transfer_number'   => "WHS-{$dateKey}-{$nextSequence}",
                    'from_warehouse_id' => $fromId,
                    'to_warehouse_id'   => $toId,
                    'inventory_value'   => round($totalValue, 2),
                    'created_by'        => auth()->id(),
                    'transfer_date'     => now(),
                ]);

                // 8.5) حفظ تفاصيل التحويل
                $now = now();
                $rowsToInsert = [];

                foreach ($transferItemsData as $item) {
                    $rowsToInsert[] = array_merge($item, [
                        'warehouse_transfer_id' => $warehouseTransfer->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $variantProductIds = collect($transferItemsData)->pluck('product_id')->unique()->all();
                foreach ($totals as $productId => $quantity) {
                    if (in_array($productId, $variantProductIds, true)) continue;

                    $snap = $productDestSnapshots[$productId] ?? null;
                    if (!$snap) continue;

                    // ✅ FIX #2: نفس التكلفة
                    $productCost = $productCosts[$productId] ?? 0.0;

                    $rowsToInsert[] = [
                        'warehouse_transfer_id' => $warehouseTransfer->id,
                        'product_id'            => $productId,
                        'product_unit_id'       => null,
                        'color_id'              => null,
                        'quantity'              => (int) $quantity,
                        'source_before'         => $snap['source_before'],
                        'source_after'          => $snap['source_after'],
                        'destination_before'    => $snap['destination_before'],
                        'destination_after'     => $snap['destination_after'],
                        'unit_cost'             => $productCost,
                        'total_cost'            => round($quantity * $productCost, 2),
                        'note'                  => null,
                        'created_at'            => $now,
                        'updated_at'            => $now,
                    ];
                }

                if (!empty($rowsToInsert)) {
                    DB::table('warehouse_transfer_items')->insert($rowsToInsert);
                }

                // 9) الخزائن
                $treasuryFor = function ($branchId) {
                    $query = Treasury::query();
                    if ($branchId) {
                        $query->where('branch_id', $branchId);
                    }
                    return $query->orderByDesc('is_main')->orderBy('id')->lockForUpdate()->first()
                        ?? Treasury::where('is_main', true)->orderBy('id')->lockForUpdate()->first();
                };

                $sourceTreasury = $treasuryFor($fromWarehouse->branch_id);
                $destinationTreasury = $treasuryFor($toWarehouse->branch_id);

                if (
                    $totalValue > 0 &&
                    $sourceTreasury &&
                    $destinationTreasury &&
                    $sourceTreasury->id !== $destinationTreasury->id
                ) {
                    $sourceTreasury->increment('balance', $totalValue);
                    $destinationTreasury->decrement('balance', $totalValue);

                    TreasuryTransaction::create([
                        'treasury_id' => $sourceTreasury->id,
                        'type' => 'in',
                        'amount' => $totalValue,
                        'reference_type' => WarehouseTransfer::class,
                        'reference_id' => $warehouseTransfer->id,
                        'description' => 'قيمة مخزون محول إلى المخزن ' . $toWarehouse->name,
                        'created_by' => auth()->id(),
                    ]);

                    TreasuryTransaction::create([
                        'treasury_id' => $destinationTreasury->id,
                        'type' => 'out',
                        'amount' => $totalValue,
                        'reference_type' => WarehouseTransfer::class,
                        'reference_id' => $warehouseTransfer->id,
                        'description' => 'قيمة مخزون محول من المخزن ' . $fromWarehouse->name,
                        'created_by' => auth()->id(),
                    ]);
                }

                return [
                    'transfer_id'     => $warehouseTransfer->id,
                    'transfer_number' => $warehouseTransfer->transfer_number,
                    'transfer_date'   => $warehouseTransfer->transfer_date,
                    'value'           => round($totalValue, 2),
                    'products_count'  => $totals->count(),
                    'total_quantity'  => (int) $totals->sum(),
                    'items'           => $transferItemsData,
                    'items_count'     => count($rowsToInsert),
                ];
            }, 3);

            // 10) تحقق بعد الـ commit
            $verification = [
                'from_warehouse_id' => (int) $validated['from_warehouse_id'],
                'to_warehouse_id'   => (int) $validated['to_warehouse_id'],
                'products' => [],
            ];

            foreach (collect($validated['products'])->groupBy('product_id') as $productId => $rows) {
                $verification['products'][$productId] = [
                    'source_stock' => (int) DB::table('product_warehouse')
                        ->where('product_id', $productId)
                        ->where('warehouse_id', $validated['from_warehouse_id'])
                        ->value('stock'),
                    'destination_stock' => (int) DB::table('product_warehouse')
                        ->where('product_id', $productId)
                        ->where('warehouse_id', $validated['to_warehouse_id'])
                        ->value('stock'),
                ];
            }

            Log::info('WAREHOUSE TRANSFER AFTER COMMIT', $verification);

            return response()->json([
                'result' => 'Success',
                'message' => 'تم تحويل المنتجات وتحديث رصيد المخزنين بنجاح',
                'data' => $result,
                'verification' => $verification,
                'inventory_value' => $result['value'],
                'transfer_id' => $result['transfer_id'],
                'transfer_number' => $result['transfer_number'],
                'transfer_date' => $result['transfer_date'],
                'status' => 200,
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('WAREHOUSE TRANSFER ERROR', [
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
                        foreach ($item['variants'] as $v) {
                            $colorRec = DB::table('product_unit_colors')
                                ->where('product_unit_id', $v['product_unit_id'])
                                ->where('color_id', $v['color_id'])
                                ->whereNull('deleted_at')
                                ->first();

                            if (!$colorRec) continue;

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

                        ProductUnitColorWarehouse::syncProduct($productId);

                        $countedStock = (int) (DB::table('product_warehouse')
                            ->where('warehouse_id', $warehouseId)
                            ->where('product_id', $productId)
                            ->value('stock') ?? 0);
                    } else {
                        $countedStock = (int) ($item['counted_stock'] ?? 0);

                        DB::table('product_warehouse')
                            ->where('id', $pw->id)
                            ->update([
                                'stock' => $countedStock,
                                'updated_at' => now(),
                            ]);

                        ProductUnitColorWarehouse::syncProduct($productId);
                    }

                    InventoryLog::create([
                        'warehouse_id' => $warehouseId,
                        'product_id' => $productId,
                        'system_stock' => $systemStock,
                        'counted_stock' => $countedStock,
                        'difference' => $countedStock - $systemStock,
                        'note' => $request->note,
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