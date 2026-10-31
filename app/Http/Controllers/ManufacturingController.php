<?php
namespace App\Http\Controllers;

use App\Manufacturing\Models\BomVersion;
use App\Manufacturing\Models\ManufacturingEquipment;
use App\Manufacturing\Models\ManufacturingOrder;
use App\Manufacturing\Services\ManufacturingService;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManufacturingController extends Controller
{
    private function service(): ManufacturingService { return new ManufacturingService(auth()->id()); }
    private function key(Request $r): string { return (string)($r->input('idempotency_key') ?: $r->header('Idempotency-Key')); }
    private function branchWarehouse(Request $r): void { if($r->filled('warehouse_id') && $r->filled('branch_id')) { $branch=(int)$r->branch_id; abort_unless(\DB::table('warehouses')->where(['id'=>$r->warehouse_id,'branch_id'=>$branch])->exists(),403,'Warehouse is outside branch'); } }

    public function bomIndex(Request $r) { return BomVersion::with('items')->when($r->branch_id,fn($q)=>$q->where('branch_id',$r->branch_id))->latest()->paginate($r->integer('per_page',25)); }
    public function bomStore(Request $r) { $d=$r->validate(['product_id'=>'required|exists:products,id','branch_id'=>'nullable|exists:branches,id','version'=>'required|string|max:50','status'=>['nullable',Rule::in(['draft','active','obsolete'])],'yield_quantity'=>'nullable|numeric|gt:0','items'=>'array','items.*.component_product_id'=>'required|exists:products,id','items.*.quantity'=>'required|numeric|gt:0','items.*.parent_item_id'=>'nullable|exists:bom_items,id','items.*.child_bom_version_id'=>'nullable|exists:bom_versions,id']); return \DB::transaction(function()use($d){$b=BomVersion::create($d+['created_by'=>auth()->id()]);foreach($d['items']??[] as $item)$b->items()->create($item);return response()->json($b->load('items'),201);}); }
    public function bomShow(BomVersion $bomVersion) { return $bomVersion->load(['product','items.component','items.childBom']); }
    public function bomUpdate(Request $r,BomVersion $bomVersion) { abort_if($bomVersion->status==='active',422,'Active BOM revisions are immutable');$d=$r->validate(['version'=>'sometimes|string|max:50','status'=>['sometimes',Rule::in(['draft','active','obsolete'])],'yield_quantity'=>'sometimes|numeric|gt:0','items'=>'sometimes|array']);return \DB::transaction(function()use($d,$bomVersion){$items=$d['items']??null;unset($d['items']);$bomVersion->update($d);if($items!==null){$bomVersion->items()->delete();foreach($items as $item)$bomVersion->items()->create($item);}return $bomVersion->load('items');}); }
    public function bomDestroy(BomVersion $bomVersion) { abort_if($bomVersion->status==='active',422,'Active BOM revisions are immutable');$bomVersion->delete();return response()->noContent(); }

    public function productStore(Request $r) { $d=$r->validate(['name'=>'required|string','sku'=>'nullable|string','product_type'=>['required',Rule::in(['good','service','material','semi_finished','finished'])],'stock_unit'=>'nullable|string','base_unit'=>'nullable|string','conversion_factor'=>'nullable|numeric|gt:0','tracking'=>['nullable',Rule::in(['none','lot','serial'])],'dimensions'=>'nullable|array','price'=>'nullable|numeric','cost'=>'nullable|numeric']);return Product::create($d); }
    public function productUpdate(Request $r,Product $product) { $d=$r->validate(['name'=>'sometimes|string','product_type'=>[Rule::in(['good','service','material','semi_finished','finished'])],'stock_unit'=>'nullable|string','base_unit'=>'nullable|string','conversion_factor'=>'nullable|numeric|gt:0','tracking'=>[Rule::in(['none','lot','serial'])],'dimensions'=>'nullable|array']);$product->update($d);return $product->fresh(); }
    public function productIndex(Request $r) { return Product::whereIn('product_type',['material','semi_finished','finished'])->paginate($r->integer('per_page',25)); }

    public function equipmentIndex() { return ManufacturingEquipment::latest()->paginate(25); }
    public function equipmentStore(Request $r) { return ManufacturingEquipment::create($r->validate(['code'=>'required|string|unique:manufacturing_equipment,code','name'=>'required|string','branch_id'=>'nullable|exists:branches,id','status'=>'nullable|string','hourly_rate'=>'nullable|numeric|min:0','notes'=>'nullable|string'])); }
    public function equipmentUpdate(Request $r,ManufacturingEquipment $equipment) { $d=$r->validate(['name'=>'sometimes|string','status'=>'sometimes|string','branch_id'=>'nullable|exists:branches,id','hourly_rate'=>'nullable|numeric|min:0','notes'=>'nullable|string']);$equipment->update($d);return $equipment->fresh(); }
    public function equipmentDestroy(ManufacturingEquipment $equipment) { $equipment->delete();return response()->noContent(); }

    public function orderIndex(Request $r) { return ManufacturingOrder::with(['product','materials','operations'])->when($r->branch_id,fn($q)=>$q->where('branch_id',$r->branch_id))->latest()->paginate($r->integer('per_page',25)); }
    public function orderStore(Request $r) { $this->branchWarehouse($r);$d=$r->validate(['product_id'=>'required|exists:products,id','bom_version_id'=>'nullable|exists:bom_versions,id','branch_id'=>'nullable|exists:branches,id','warehouse_id'=>'required|exists:warehouses,id','source_invoice_id'=>'nullable|exists:invoices,id','source_sales_order_id'=>'nullable|integer','planned_quantity'=>'required|numeric|gt:0','planned_at'=>'nullable|date','operations'=>'nullable|array','operations.*.name'=>'required|string','operations.*.sequence'=>'nullable|integer|min:1','operations.*.equipment_id'=>'nullable|exists:manufacturing_equipment,id','operations.*.planned_minutes'=>'nullable|numeric|min:0','notes'=>'nullable|string']);return response()->json($this->service()->createOrder($d),201); }
    public function orderShow(ManufacturingOrder $manufacturingOrder) { return $manufacturingOrder->load(['product','bomVersion','materials.product','operations','statusHistory','movements','costs']); }
    public function availability(ManufacturingOrder $o) { return $this->service()->availability($o); }
    public function reserve(Request $r,ManufacturingOrder $o) { return $this->service()->reserve($o); }
    public function issue(Request $r,ManufacturingOrder $o) { $d=$r->validate(['idempotency_key'=>'required|string','items'=>'required|array|min:1','items.*.material_id'=>'required|integer','items.*.quantity'=>'required|numeric|gt:0']);return $this->service()->issue($o,$d['items'],$d['idempotency_key']); }
    public function returnMaterial(Request $r,ManufacturingOrder $o) { $d=$r->validate(['idempotency_key'=>'required|string','items'=>'required|array','items.*.material_id'=>'required|integer','items.*.quantity'=>'required|numeric|gt:0']);return $this->service()->returnMaterial($o,$d['items'],$d['idempotency_key']); }
    public function scrap(Request $r,ManufacturingOrder $o) { $d=$r->validate(['idempotency_key'=>'required|string','items'=>'required|array','items.*.material_id'=>'required|integer','items.*.quantity'=>'required|numeric|gt:0']);return $this->service()->scrap($o,$d['items'],$d['idempotency_key']); }
    public function operation(Request $r,ManufacturingOrder $o, int $operation) { $d=$r->validate(['idempotency_key'=>'required|string','action'=>['required',Rule::in(['start','complete','labor','machine'])],'actual_minutes'=>'nullable|numeric|min:0','minutes'=>'nullable|numeric|min:0','cost'=>'nullable|numeric|min:0']);return $this->service()->operation($o,$operation,$d['action'],$d,$d['idempotency_key']); }
    public function quality(Request $r,ManufacturingOrder $o) { $d=$r->validate(['idempotency_key'=>'required|string','result'=>['required',Rule::in(['pass','reject','rework'])],'quantity'=>'nullable|numeric|gt:0','manufacturing_operation_id'=>'nullable|exists:manufacturing_operations,id','reason'=>'nullable|string']);return $this->service()->quality($o,$d,$d['idempotency_key']); }
    public function receive(Request $r,ManufacturingOrder $o) { $d=$r->validate(['idempotency_key'=>'required|string','quantity'=>'required|numeric|gt:0']);return $this->service()->receive($o,$d['quantity'],$d['idempotency_key']); }
    public function cancel(Request $r,ManufacturingOrder $o) { return $this->service()->cancel($o,$r->validate(['idempotency_key'=>'required|string'])['idempotency_key']); }
    public function close(Request $r,ManufacturingOrder $o) { return $this->service()->close($o,$r->validate(['idempotency_key'=>'required|string'])['idempotency_key']); }
}
