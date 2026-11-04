<?php

namespace App\Console\Commands;

use App\Models\ProductUnitColorWarehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileInventory extends Command
{
    protected $signature = 'inventory:reconcile';
    protected $description = 'Sync all aggregated stock values from product_unit_color_warehouse';

    public function handle()
    {
        $productIds = DB::table('products')->pluck('id');
        $bar = $this->output->createProgressBar($productIds->count());
        $bar->start();

        foreach ($productIds as $pid) {
            ProductUnitColorWarehouse::syncProduct((int) $pid);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("تم تصحيح {$productIds->count()} منتج");

        return self::SUCCESS;
    }
}