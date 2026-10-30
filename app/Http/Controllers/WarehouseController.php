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
            'products.*.product_unit_id' => 'nullable|integer',
            'products.*.color_id' => 'nullable|integer',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.note' => 'nullable|string|max:1000',
        ]);

        try {
            $result = DB::transaction(function () use ($validated) {
                $fromWarehouse = Warehouse::whereKey($validated['from_warehouse_id'])->lockForUpdate()->firstOrFail();
                $toWarehouse = Warehouse::whereKey($validated['to_warehouse_id'])->lockForUpdate()->firstOrFail();
                $totals = collect($validated['products'])->groupBy('product_id')->map(fn ($items) => $items->sum('quantity'));
                $stockRows = [];
                $totalValue = 0.0;

                foreach ($totals as $productId => $quantity) {
                    $stockRows[$productId] = DB::table('product_warehouse')
                        ->where('warehouse_id', $fromWarehouse->id)
                        ->where('product_id', $productId)
                        ->lockForUpdate()->first();
                    if (!$stockRows[$productId] || $stockRows[$productId]->stock < $quantity) {
                        throw new \RuntimeException('الكمية غير متاحة للتحويل في مخزن المصدر');
                    }
                    $product = DB::table('products')->where('id', $productId)->first();
                    $unitCost = (float) ($stockRows[$productId]->cost ?? $product->cost ?? 0);
                    $totalValue += $unitCost * $quantity;
                }

                foreach ($validated['products'] as $item) {
                    $productId = (int) $item['product_id'];
                    $quantity = (int) $item['quantity'];
                    $unitId = (int) ($item['product_unit_id'] ?? 0);
                    $colorId = (int) ($item['color_id'] ?? 0);

                    if ($unitId > 0 && $colorId > 0) {
                        $variant = DB::table('product_unit_colors as colors')
                            ->join('product_units as units', 'units.id', '=', 'colors.product_unit_id')
                            ->where('colors.product_unit_id', $unitId)
                            ->where('colors.color_id', $colorId)
                            ->where('units.product_id', $productId)
                            ->whereNull('colors.deleted_at')
                            ->whereNull('units.deleted_at')
                            ->select('colors.id', 'colors.stock')
                            ->lockForUpdate()->first();
                        if (!$variant || $variant->stock < $quantity) {
                            throw new \RuntimeException('كمية المقاس واللون غير متاحة للتحويل');
                        }
                        // Variant stock is currently global (not warehouse-scoped), so keep its total
                        // unchanged; per-warehouse aggregate stock below is moved atomically.
                    }
                }

                foreach ($totals as $productId => $quantity) {
                    $source = $stockRows[$productId];
                    DB::table('product_warehouse')->where('id', $source->id)->decrement('stock', $quantity);
                    $destinationStock = DB::table('product_warehouse')
                        ->where('warehouse_id', $toWarehouse->id)->where('product_id', $productId)
                        ->lockForUpdate()->first();
                    if ($destinationStock) {
                        DB::table('product_warehouse')->where('id', $destinationStock->id)->increment('stock', $quantity);
                        if ($destinationStock->cost === null && $source->cost !== null) {
                            DB::table('product_warehouse')->where('id', $destinationStock->id)->update(['cost' => $source->cost]);
                        }
                    } else {
                        DB::table('product_warehouse')->insert([
                            'warehouse_id' => $toWarehouse->id, 'product_id' => $productId,
                            'stock' => $quantity, 'cost' => $source->cost,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }

                $warehouseTransfer = \App\Models\WarehouseTransfer::create([
                    'from_warehouse_id' => $fromWarehouse->id,
                    'to_warehouse_id' => $toWarehouse->id,
                    'inventory_value' => $totalValue,
                    'created_by' => auth()->id(),
                ]);

                // Inventory valuation follows the requested branch direction: source treasury increases,
                // destination branch treasury decreases. Prefer each branch's main treasury, then global main.
                $treasuryFor = function ($branchId) {
                    $query = \App\Models\Treasury::query();
                    if ($branchId) $query->where('branch_id', $branchId);
                    return $query->orderByDesc('is_main')->orderBy('id')->lockForUpdate()->first()
                        ?? \App\Models\Treasury::where('is_main', true)->lockForUpdate()->first();
                };
                $sourceTreasury = $treasuryFor($fromWarehouse->branch_id);
                $destinationTreasury = $treasuryFor($toWarehouse->branch_id);
                if ($totalValue > 0 && $sourceTreasury && $destinationTreasury && $sourceTreasury->id !== $destinationTreasury->id) {
                    $sourceTreasury->increment('balance', $totalValue);
                    $destinationTreasury->decrement('balance', $totalValue);
                    \App\Models\TreasuryTransaction::create([
                        'treasury_id' => $sourceTreasury->id, 'type' => 'in', 'amount' => $totalValue,
                        'reference_type' => \App\Models\WarehouseTransfer::class, 'reference_id' => $warehouseTransfer->id,
                        'description' => 'قيمة مخزون محول إلى المخزن '.$toWarehouse->name,
                        'created_by' => auth()->id(),
                    ]);
                    \App\Models\TreasuryTransaction::create([
                        'treasury_id' => $destinationTreasury->id, 'type' => 'out', 'amount' => $totalValue,
                        'reference_type' => \App\Models\WarehouseTransfer::class, 'reference_id' => $warehouseTransfer->id,
                        'description' => 'قيمة مخزون مستلم من المخزن '.$fromWarehouse->name,
                        'created_by' => auth()->id(),
                    ]);
                }

                return ['value' => round($totalValue, 2)];
            });

            return response()->json([
                'result' => 'Success',
                'message' => 'تم تحويل المنتجات بنجاح',
                'inventory_value' => $result['value'],
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
