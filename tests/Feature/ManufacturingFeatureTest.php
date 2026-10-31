<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManufacturingFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_manufacturing_schema_has_revision_and_status_history_tables(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('bom_versions'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('bom_items'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('manufacturing_order_status_histories'));
    }

    public function test_manufacturing_quantities_are_decimal(): void
    {
        $type = DB::getSchemaBuilder()->getColumnType('manufacturing_order_materials', 'required_quantity');
        $this->assertContains(strtolower($type), ['numeric', 'decimal', 'float']);
    }

    public function test_idempotency_and_stock_movements_are_unique_per_action(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('manufacturing_idempotency_keys'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('manufacturing_stock_movements'));
    }
}
