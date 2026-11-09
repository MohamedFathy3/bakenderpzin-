<?php

namespace App\Http\Controllers;

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

    public function index()
    {
        try {
            $warehouse = WarehouseResource::collection($this->crudRepository->all([], [], ['*']));
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

    public function warehouseProducts(Warehouse $warehouse)
    {
        $warehouseId = $warehouse->id;

        $products = $warehouse->products()
            ->with(['units.colors.warehouseStocks' => fn ($query) => $query->where('warehouse_id', $warehouse->id)])
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
        $products->each(function ($product) {
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
                $fromId = (int) $validated['from_warehouse_id'];
                $toId = (int) $validated['to_warehouse_id'];

                // 1) قفل المخزنين بترتيب ثابت لتفادي الـ deadlock
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

                // 2) تجميع الكميات حسب المنتج وحسب (مقاس + لون)
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
                        // منع التحويل الإجمالي لو المنتج له مقاسات وألوان
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
                        ];
                    }
                    $variantTotals[$key]['quantity'] += $quantity;
                }

                // 3) التحقق من رصيد المنتجات في المخزن المصدر وحساب القيمة
                $stockRows = [];
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

                    $unitCost = $source->cost;
                    if ($unitCost === null) {
                        $unitCost = DB::table('products')->where('id', $productId)->value('cost');
                    }

                    $stockRows[$productId] = $source;
                    $totalValue += $quantity * (float) ($unitCost ?? 0);
                }

                // 4) التحقق من أرصدة المقاس واللون في المخزن المصدر
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
                        'product_id' => $productId,
                        'color_record_id' => $color->id,
                        'source_stock_id' => $sourceColorStock->id,
                        'quantity' => $quantity,
                    ];
                }

                // 5) تحويل رصيد المنتجات
                foreach ($totals as $productId => $quantity) {
                    $source = $stockRows[$productId];

                    $affected = DB::table('product_warehouse')
                        ->where('id', $source->id)
                        ->where('stock', '>=', $quantity)
                        ->decrement('stock', $quantity);

                    if ($affected !== 1) {
                        throw new \RuntimeException("فشل خصم المخزون من المخزن المصدر. Product: {$productId}");
                    }

                    $destination = DB::table('product_warehouse')
                        ->where('warehouse_id', $toId)
                        ->where('product_id', $productId)
                        ->lockForUpdate()
                        ->first();

                    if ($destination) {
                        DB::table('product_warehouse')
                            ->where('id', $destination->id)
                            ->increment('stock', $quantity);

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
                }

                // 6) تحويل رصيد المقاس واللون
                foreach ($variantRows as $variant) {
                    $colorRecordId = $variant['color_record_id'];
                    $quantity = $variant['quantity'];

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
                }

                // 7) مزامنة كل الـ aggregates (colors + product_warehouse + products.stock)
                $affectedProductIds = collect(array_column($variantRows, 'product_id'))
                    ->merge($totals->keys())
                    ->unique()
                    ->values();

                foreach ($affectedProductIds as $pid) {
                    ProductUnitColorWarehouse::syncProduct((int) $pid);
                }

                // 8) سجل التحويل
                $warehouseTransfer = WarehouseTransfer::create([
                    'from_warehouse_id' => $fromId,
                    'to_warehouse_id' => $toId,
                    'inventory_value' => round($totalValue, 2),
                    'created_by' => auth()->id(),
                ]);

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
                    'transfer_id' => $warehouseTransfer->id,
                    'value' => round($totalValue, 2),
                    'products_count' => $totals->count(),
                    'total_quantity' => (int) $totals->sum(),
                ];
            }, 3);

            // 10) تحقق بعد الـ commit
            $verification = [
                'from_warehouse_id' => (int) $validated['from_warehouse_id'],
                'to_warehouse_id' => (int) $validated['to_warehouse_id'],
                'products' => [],
            ];

            foreach (collect($validated['products'])->groupBy('product_id') as $productId => $rows) {
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

            Log::info('WAREHOUSE TRANSFER AFTER COMMIT', $verification);

            return response()->json([
                'result' => 'Success',
                'message' => 'تم تحويل المنتجات وتحديث رصيد المخزنين بنجاح',
                'data' => $result,
                'verification' => $verification,
                'inventory_value' => $result['value'],
                'transfer_id' => $result['transfer_id'],
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

    public function transfers()
    {
        $transfers = WarehouseTransfer::query()
            ->with([
                'sourceWarehouse:id,name,name_ar',
                'destinationWarehouse:id,name,name_ar',
            ])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'result' => 'Success',
            'data' => $transfers,
            'status' => 200,
        ]);
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

                    if (!$pw) {
                        continue;
                    }

                    $systemStock = (int) $pw->stock;

                    if (!empty($item['variants'])) {
                        // 1) تحديث رصيد كل لون في المخزن
                        foreach ($item['variants'] as $v) {
                            $colorRec = DB::table('product_unit_colors')
                                ->where('product_unit_id', $v['product_unit_id'])
                                ->where('color_id', $v['color_id'])
                                ->whereNull('deleted_at')
                                ->first();

                            if (!$colorRec) {
                                continue;
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

                        // 2) مزامنة كاملة
                        ProductUnitColorWarehouse::syncProduct($productId);

                        // 3) قراءة الرصيد النهائي بعد المزامنة
                        $countedStock = (int) (DB::table('product_warehouse')
                            ->where('warehouse_id', $warehouseId)
                            ->where('product_id', $productId)
                            ->value('stock') ?? 0);
                    } else {
                        // منتج بدون ألوان
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
