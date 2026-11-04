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
        $products = $warehouse->products()
            ->withPivot('stock')
            ->wherePivot('stock', '>', 0)
            ->get();

        return WarehouseProductResource::collection($products);
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

                foreach ($validated['products'] as $item) {
                    $productId = (int) $item['product_id'];
                    $quantity = (int) $item['quantity'];
                    $unitId = (int) ($item['product_unit_id'] ?? 0);
                    $colorId = (int) ($item['color_id'] ?? 0);

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

                $warehouseTransfer = \App\Models\WarehouseTransfer::create([
                    'from_warehouse_id' => $fromWarehouse->id,
                    'to_warehouse_id' => $toWarehouse->id,
                    'inventory_value' => $totalValue,
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
        } catch (\Illuminate\Validation\ValidationException $e) {
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

            DB::commit();

            return response()->json([
                'message' => 'تم تنفيذ الجرد بنجاح'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => $e->getMessage()
            ], 422);
        }
    }

    public function updateCountedStock(Request $request, InventoryLog $inventoryLog)
    {
        $request->validate([
            'counted_stock' => 'required|integer|min:0',
            'note' => 'nullable|string',
        ]);

        // تحديث counted_stock و note
        $inventoryLog->update([
            'counted_stock' => $request->counted_stock,
            'note' => $request->note,
            'difference' => $request->counted_stock - $inventoryLog->system_stock, // تحديث الفرق
        ]);

        return response()->json([
            'result' => 'Success',
            'message' => 'Inventory log updated successfully',
            'data' => $inventoryLog,
        ]);
    }


}
