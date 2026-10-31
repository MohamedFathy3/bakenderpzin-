<?php
namespace App\Manufacturing\Services;

use App\Manufacturing\Models\BomItem;
use App\Manufacturing\Models\BomVersion;
use App\Manufacturing\Models\ManufacturingCostEntry;
use App\Manufacturing\Models\ManufacturingEquipment;
use App\Manufacturing\Models\ManufacturingOperation;
use App\Manufacturing\Models\ManufacturingOrder;
use App\Manufacturing\Models\ManufacturingOrderMaterial;
use App\Manufacturing\Models\ManufacturingOrderStatusHistory;
use App\Manufacturing\Models\ManufacturingOperationLog;
use App\Manufacturing\Models\ManufacturingStockMovement;
use App\Manufacturing\Models\QualityInspection;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ManufacturingService
{
    public function __construct(private readonly ?int $userId = null) {}

    public function createOrder(array $data): ManufacturingOrder
    {
        return DB::transaction(function () use ($data) {
            $product = Product::findOrFail($data['product_id']);
            $warehouse = DB::table('warehouses')->where('id', $data['warehouse_id'])->first();
            if (!$warehouse || ($data['branch_id'] ?? $warehouse->branch_id) != $warehouse->branch_id && ($data['branch_id'] ?? null) !== null) throw new RuntimeException('Warehouse does not belong to branch');
            $bom = !empty($data['bom_version_id']) ? BomVersion::findOrFail($data['bom_version_id']) : BomVersion::where('product_id',$product->id)->where('status','active')->latest('id')->first();
            $order = ManufacturingOrder::create(['order_number'=>$data['order_number'] ?? 'MO-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),'product_id'=>$product->id,'bom_version_id'=>$bom?->id,'branch_id'=>$data['branch_id'] ?? $warehouse->branch_id,'warehouse_id'=>$warehouse->id,'source_invoice_id'=>$data['source_invoice_id'] ?? null,'source_sales_order_id'=>$data['source_sales_order_id'] ?? null,'planned_quantity'=>$data['planned_quantity'],'status'=>'draft','planned_at'=>$data['planned_at'] ?? null,'created_by'=>$this->userId,'notes'=>$data['notes'] ?? null]);
            if ($bom) $this->explodeBom($order, $bom, (float)$data['planned_quantity']);
            foreach ($data['operations'] ?? [] as $i => $op) $order->operations()->create(['sequence'=>$op['sequence'] ?? $i+1,'name'=>$op['name'],'equipment_id'=>$op['equipment_id'] ?? null,'planned_minutes'=>$op['planned_minutes'] ?? 0]);
            $this->transition($order, 'draft', 'Order created');
            return $order->load(['materials','operations','bomVersion']);
        });
    }

    private function explodeBom(ManufacturingOrder $order, BomVersion $bom, float $factor): void
    {
        foreach ($bom->items()->with(['childBom','children'])->get() as $item) {
            $qty = $factor * (float)$item->quantity * (1 + ((float)$item->scrap_percent / 100));
            $material = $order->materials()->create(['bom_item_id'=>$item->id,'product_id'=>$item->component_product_id,'required_quantity'=>$qty,'unit_cost'=>(float)($item->component->cost ?? 0)]);
            if ($item->childBom) $this->explodeBom($order, $item->childBom, $qty / max((float)$item->childBom->yield_quantity, 0.000001));
        }
    }

    public function transition(ManufacturingOrder $order, string $status, ?string $reason = null, ?string $key = null): ManufacturingOrder
    {
        $allowed=['draft'=>['planned','cancelled'],'planned'=>['reserved','draft','cancelled'],'reserved'=>['in_progress','cancelled'],'in_progress'=>['quality_hold','completed','cancelled'],'quality_hold'=>['in_progress','completed','cancelled'],'completed'=>['closed'],'cancelled'=>[],'closed'=>[]];
        if ($order->status !== $status) { if (!in_array($status,$allowed[$order->status] ?? [],true)) throw new RuntimeException("Invalid status transition {$order->status} -> {$status}"); $old=$order->status; $order->update(['status'=>$status]+match($status){'in_progress'=>['started_at'=>now()],'completed'=>['completed_at'=>now()],'closed'=>['closed_at'=>now()],'cancelled'=>[],'default'=>[]}); ManufacturingOrderStatusHistory::create(['manufacturing_order_id'=>$order->id,'from_status'=>$old,'to_status'=>$status,'reason'=>$reason,'created_by'=>$this->userId,'idempotency_key'=>$key]); }
        return $order->fresh();
    }

    public function availability(ManufacturingOrder $order): array
    {
        $rows=[]; foreach ($order->materials as $m) { $stock=(float)(DB::table('product_warehouse')->where(['product_id'=>$m->product_id,'warehouse_id'=>$order->warehouse_id])->value('stock') ?? 0); $rows[]=['material_id'=>$m->id,'product_id'=>$m->product_id,'required'=>(float)$m->required_quantity,'available'=>$stock,'shortage'=>max(0,(float)$m->required_quantity-(float)$m->reserved_quantity-(float)$m->issued_quantity)]; } return ['available'=>collect($rows)->every(fn($r)=>$r['available'] >= $r['shortage']),'items'=>$rows];
    }

    public function reserve(ManufacturingOrder $order): ManufacturingOrder
    { return DB::transaction(function() use($order){if($order->status==='draft') $this->transition($order,'planned','Order planned'); $a=$this->availability($order); if(!$a['available']) throw new RuntimeException('Insufficient material availability'); foreach($order->materials as $m){$need=max(0,(float)$m->required_quantity-(float)$m->reserved_quantity-(float)$m->issued_quantity); $m->increment('reserved_quantity',$need); $order->increment('reserved_quantity',$need);} return $this->transition($order->fresh(),'reserved','Materials reserved');}); }

    public function issue(ManufacturingOrder $order, array $items, string $key): ManufacturingOrder
    { return $this->idempotent($key,'issue',function() use($order,$items,$key){return DB::transaction(function() use($order,$items,$key){foreach($items as $row){$m=$order->materials()->lockForUpdate()->findOrFail($row['material_id']);$qty=(float)$row['quantity'];if($m->product && $m->product->product_type==='service') throw new RuntimeException('Services do not consume inventory');$stock=DB::table('product_warehouse')->where(['product_id'=>$m->product_id,'warehouse_id'=>$order->warehouse_id])->lockForUpdate()->first();if(!$stock || (float)$stock->stock < $qty) throw new RuntimeException('Negative stock is not allowed');DB::table('product_warehouse')->where('id',$stock->id)->decrement('stock',$qty);$m->increment('issued_quantity',$qty);$m->decrement('reserved_quantity',min($qty,(float)$m->reserved_quantity));$this->movement($order,$m->product_id,'issue',$qty,$key);$this->inventoryLog($order,$m->product_id,$stock->stock,$stock->stock-$qty,'Manufacturing material issue');}if($order->status==='planned')$this->transition($order,'reserved');return $order->fresh();});}); }

    public function returnMaterial(ManufacturingOrder $order,array $items,string $key): ManufacturingOrder { return $this->idempotent($key,'return',fn()=>DB::transaction(function()use($order,$items,$key){foreach($items as $row){$m=$order->materials()->lockForUpdate()->findOrFail($row['material_id']);$qty=(float)$row['quantity'];if($qty>(float)$m->issued_quantity-(float)$m->returned_quantity)throw new RuntimeException('Return exceeds issued quantity');$this->increaseStock($order,$m->product_id,$qty);$m->increment('returned_quantity',$qty);$this->movement($order,$m->product_id,'return',$qty,$key);}return $order->fresh();})); }
    public function scrap(ManufacturingOrder $order,array $items,string $key): ManufacturingOrder { return $this->idempotent($key,'scrap',fn()=>DB::transaction(function()use($order,$items,$key){foreach($items as $row){$m=$order->materials()->lockForUpdate()->findOrFail($row['material_id']);$qty=(float)$row['quantity'];if($qty>(float)$m->issued_quantity-(float)$m->scrapped_quantity)throw new RuntimeException('Scrap exceeds issued quantity');$m->increment('scrapped_quantity',$qty);$order->increment('scrapped_quantity',$qty);$this->movement($order,$m->product_id,'scrap',$qty,$key);}return $order->fresh();})); }

    public function operation(ManufacturingOrder $order,int $operationId,string $action,array $data,string $key): ManufacturingOrder { return $this->idempotent($key,$action,fn()=>DB::transaction(function()use($order,$operationId,$action,$data,$key){$op=$order->operations()->lockForUpdate()->findOrFail($operationId);$previous=$order->operations()->where('sequence','<',$op->sequence)->whereNotIn('status',['completed','skipped'])->exists();if(in_array($action,['start','complete'],true)&&$previous)throw new RuntimeException('Operations must be completed in sequence');if($action==='start')$op->update(['status'=>'in_progress']);if($action==='complete')$op->update(['status'=>'completed','actual_minutes'=>$data['actual_minutes']??$op->actual_minutes]);if(in_array($action,['labor','machine'],true)){ $minutes=(float)($data['minutes']??0);$cost=(float)($data['cost']??0);ManufacturingOperationLog::create(['manufacturing_operation_id'=>$op->id,'user_id'=>$this->userId,'action'=>$action,'minutes'=>$minutes,'labor_cost'=>$action==='labor'?$cost:0,'machine_cost'=>$action==='machine'?$cost:0,'idempotency_key'=>$key]);ManufacturingCostEntry::create(['manufacturing_order_id'=>$order->id,'manufacturing_operation_id'=>$op->id,'cost_type'=>$action,'quantity'=>$minutes/60,'unit_cost'=>$minutes? $cost/($minutes/60):0,'amount'=>$cost]);}$this->transition($order->fresh(),$order->status==='reserved'?'in_progress':$order->status);return $order->fresh();})); }

    public function quality(ManufacturingOrder $order,array $data,string $key): ManufacturingOrder { return $this->idempotent($key,'quality',fn()=>DB::transaction(function()use($order,$data){QualityInspection::create(['manufacturing_order_id'=>$order->id,'manufacturing_operation_id'=>$data['manufacturing_operation_id']??null,'quantity'=>$data['quantity']??$order->planned_quantity,'result'=>$data['result'],'reason'=>$data['reason']??null,'inspected_by'=>$this->userId]);if($data['result']==='reject')$this->transition($order,'quality_hold','Quality rejected');elseif($data['result']==='rework')$this->transition($order,'in_progress','Rework required');elseif($data['result']==='pass'&&$order->status==='quality_hold')$this->transition($order,'in_progress','Quality passed');return $order->fresh();})); }
    public function receive(ManufacturingOrder $order,float $qty,string $key): ManufacturingOrder { return $this->idempotent($key,'receive',fn()=>DB::transaction(function()use($order,$qty,$key){if($qty<=0)throw new RuntimeException('Quantity must be positive');$this->increaseStock($order,$order->product_id,$qty);$order->increment('produced_quantity',$qty);$this->movement($order,$order->product_id,'receive',$qty,$key);if((float)$order->produced_quantity >= (float)$order->planned_quantity)$this->transition($order->fresh(),'completed','Finished goods received');return $order->fresh();})); }
    public function cancel(ManufacturingOrder $order,string $key): ManufacturingOrder { return $this->idempotent($key,'cancel',fn()=>DB::transaction(function()use($order,$key){foreach($order->materials as $m){$issued=(float)$m->issued_quantity-(float)$m->returned_quantity;if($issued>0)$this->increaseStock($order,$m->product_id,$issued);}$this->transition($order,'cancelled','Order cancelled and issued materials reversed',$key);return $order->fresh();})); }
    public function close(ManufacturingOrder $order,string $key): ManufacturingOrder { return $this->idempotent($key,'close',fn()=>DB::transaction(fn()=> $this->transition($order,'closed','Reconciled and closed',$key))); }

    private function increaseStock(ManufacturingOrder $o,int $productId,float $qty): void { $row=DB::table('product_warehouse')->where(['product_id'=>$productId,'warehouse_id'=>$o->warehouse_id])->lockForUpdate()->first(); if($row) DB::table('product_warehouse')->where('id',$row->id)->increment('stock',$qty); else DB::table('product_warehouse')->insert(['product_id'=>$productId,'warehouse_id'=>$o->warehouse_id,'stock'=>$qty,'cost'=>0,'created_at'=>now(),'updated_at'=>now()]); }
    private function movement($o,int $p,string $type,float $q,string $key): void { ManufacturingStockMovement::create(['manufacturing_order_id'=>$o->id,'product_id'=>$p,'warehouse_id'=>$o->warehouse_id,'movement_type'=>$type,'quantity'=>$q,'idempotency_key'=>$key,'created_by'=>$this->userId]); }
    private function inventoryLog($o,int $p,float $before,float $after,string $note): void { DB::table('inventory_logs')->insert(['warehouse_id'=>$o->warehouse_id,'product_id'=>$p,'system_stock'=>(int)$before,'counted_stock'=>(int)$after,'difference'=>(int)($after-$before),'note'=>$note,'created_at'=>now(),'updated_at'=>now()]); }
    private function idempotent(string $key,string $action,callable $callback): mixed { if(!$key)throw new RuntimeException('idempotency_key is required');$existing=DB::table('manufacturing_idempotency_keys')->where(['key'=>$key,'action'=>$action,'user_id'=>$this->userId])->first();if($existing)return json_decode($existing->response,true);$result=$callback();DB::table('manufacturing_idempotency_keys')->insert(['key'=>$key,'action'=>$action,'user_id'=>$this->userId,'response'=>json_encode($result instanceof \Illuminate\Contracts\Support\Arrayable?$result->toArray():$result),'created_at'=>now(),'updated_at'=>now()]);return $result; }
}
