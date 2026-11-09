
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bom_versions')) {
            Schema::create('bom_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $t->string('version');
            $t->string('status')->default('draft');
            $t->decimal('yield_quantity', 18, 6)->default(1);
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->softDeletes();
            $t->timestamps();
            $t->unique(['product_id', 'version', 'branch_id'], 'bom_ver_prod_ver_branch_uq');
            $t->index(['product_id', 'status'], 'bom_ver_prod_status_idx');
            });
        }

        if (!Schema::hasTable('bom_items')) {
            Schema::create('bom_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bom_version_id')->constrained('bom_versions')->cascadeOnDelete();
            $t->foreignId('parent_item_id')->nullable()->constrained('bom_items')->cascadeOnDelete();
            $t->foreignId('component_product_id')->constrained('products')->restrictOnDelete();
            $t->foreignId('child_bom_version_id')->nullable()->constrained('bom_versions')->nullOnDelete();
            $t->decimal('quantity', 18, 6);
            $t->decimal('scrap_percent', 8, 4)->default(0);
            $t->string('unit')->nullable();
            $t->boolean('is_optional')->default(false);
            $t->text('notes')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->index(['bom_version_id', 'component_product_id'], 'bom_item_ver_component_idx');
            });
        }
        if (!Schema::hasTable('manufacturing_orders')) {
            Schema::create('manufacturing_orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_number')->unique();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignId('bom_version_id')->nullable()->constrained('bom_versions')->nullOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $t->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->foreignId('source_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $t->unsignedBigInteger('source_sales_order_id')->nullable();
            $t->decimal('planned_quantity', 18, 6);
            $t->decimal('produced_quantity', 18, 6)->default(0);
            $t->decimal('scrapped_quantity', 18, 6)->default(0);
            $t->decimal('reserved_quantity', 18, 6)->default(0);
            $t->string('status')->default('draft')->index();
            $t->dateTime('planned_at')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->index(['branch_id', 'warehouse_id', 'status'], 'mfg_orders_branch_wh_status_idx');
            });
        }

  if (!Schema::hasTable('manufacturing_order_status_histories')) {
    Schema::create('manufacturing_order_status_histories', function (Blueprint $t) {
        $t->id();

        $t->unsignedBigInteger('manufacturing_order_id');

        $t->foreign('manufacturing_order_id', 'mfg_hist_order_fk')
            ->references('id')
            ->on('manufacturing_orders')
            ->cascadeOnDelete();

        $t->string('from_status')->nullable();
        $t->string('to_status');
        $t->text('reason')->nullable();

        $t->foreignId('created_by')
            ->nullable()
            ->constrained('users')
            ->nullOnDelete();

        $t->string('idempotency_key')->nullable();
        $t->softDeletes();
        $t->timestamps();

        $t->unique(
            ['manufacturing_order_id', 'idempotency_key'],
            'mfg_hist_order_idem_uq'
        );
    });
}
        if (!Schema::hasTable('manufacturing_order_materials')) {
            Schema::create('manufacturing_order_materials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('manufacturing_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bom_item_id')->nullable()->constrained('bom_items')->nullOnDelete();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->decimal('required_quantity', 18, 6);
            $t->decimal('reserved_quantity', 18, 6)->default(0);
            $t->decimal('issued_quantity', 18, 6)->default(0);
            $t->decimal('returned_quantity', 18, 6)->default(0);
            $t->decimal('scrapped_quantity', 18, 6)->default(0);
            $t->decimal('unit_cost', 18, 6)->default(0);
            $t->string('status')->default('open');
            $t->softDeletes();
            $t->timestamps();
            $t->index(['manufacturing_order_id', 'product_id'], 'mfg_mat_order_product_idx');
            });
        }

        if (!Schema::hasTable('manufacturing_operations')) {
            Schema::create('manufacturing_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('manufacturing_order_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('equipment_id')->nullable();
            $t->unsignedInteger('sequence');
            $t->string('name');
            $t->string('status')->default('pending');
            $t->decimal('planned_minutes', 18, 6)->default(0);
            $t->decimal('actual_minutes', 18, 6)->default(0);
            $t->softDeletes();
            $t->timestamps();
            $t->unique(['manufacturing_order_id', 'sequence'], 'mfg_ops_order_sequence_uq');
            });
        }

        if (!Schema::hasTable('manufacturing_equipment')) {
            Schema::create('manufacturing_equipment', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('status')->default('available');
            $t->decimal('hourly_rate', 18, 6)->default(0);
            $t->text('notes')->nullable();
            $t->softDeletes();
            $t->timestamps();
            });
        }

        if (!Schema::hasTable('manufacturing_operation_logs')) {
            Schema::create('manufacturing_operation_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('manufacturing_operation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action');
            $t->decimal('minutes', 18, 6)->default(0);
            $t->decimal('labor_cost', 18, 6)->default(0);
            $t->decimal('machine_cost', 18, 6)->default(0);
            $t->text('notes')->nullable();
            $t->string('idempotency_key')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->unique(['manufacturing_operation_id', 'idempotency_key'], 'mfg_log_operation_idem_uq');
            });
        }

        if (!Schema::hasTable('quality_inspections')) {
            Schema::create('quality_inspections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('manufacturing_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('manufacturing_operation_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('quantity', 18, 6);
            $t->string('result');
            $t->text('reason')->nullable();
            $t->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $t->softDeletes();
            $t->timestamps();
            });
        }

        if (!Schema::hasTable('manufacturing_stock_movements')) {
            Schema::create('manufacturing_stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('manufacturing_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->string('movement_type');
            $t->decimal('quantity', 18, 6);
            $t->decimal('unit_cost', 18, 6)->default(0);
            $t->string('idempotency_key')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->softDeletes();
            $t->timestamps();
            $t->unique(['manufacturing_order_id', 'movement_type', 'idempotency_key'], 'mfg_stock_order_type_idem_uq');
            $t->index(['warehouse_id', 'product_id'], 'mfg_stock_wh_product_idx');
            });
        }

        if (!Schema::hasTable('manufacturing_cost_entries')) {
            Schema::create('manufacturing_cost_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('manufacturing_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('manufacturing_operation_id')->nullable()->constrained()->nullOnDelete();
            $t->string('cost_type');
            $t->decimal('quantity', 18, 6)->default(1);
            $t->decimal('unit_cost', 18, 6)->default(0);
            $t->decimal('amount', 18, 6);
            $t->text('notes')->nullable();
            $t->softDeletes();
            $t->timestamps();
            });
        }

        if (!Schema::hasTable('manufacturing_idempotency_keys')) {
            Schema::create('manufacturing_idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->string('key');
            $t->string('action');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->json('response')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->unique(['key', 'action', 'user_id'], 'mfg_idem_key_action_user_uq');
            });
        }

        // Repair the index if the table was created before a previous migration attempt failed.
        if (Schema::hasTable('manufacturing_order_materials')) {
            $existingIndex = DB::selectOne(
                "SHOW INDEX FROM `manufacturing_order_materials` WHERE Key_name = ?",
                ['mfg_mat_order_product_idx']
            );

            if (!$existingIndex) {
                Schema::table('manufacturing_order_materials', function (Blueprint $t) {
                    $t->index(
                        ['manufacturing_order_id', 'product_id'],
                        'mfg_mat_order_product_idx'
                    );
                });
            }
        }

        // Repair the unique index if a previous attempt created the table but failed
        // while adding its automatically generated (too-long) index name.
        if (Schema::hasTable('manufacturing_operation_logs')) {
            $existingIndex = DB::selectOne(
                "SHOW INDEX FROM `manufacturing_operation_logs` WHERE Key_name = ?",
                ['mfg_log_operation_idem_uq']
            );

            if (!$existingIndex) {
                Schema::table('manufacturing_operation_logs', function (Blueprint $t) {
                    $t->unique(
                        ['manufacturing_operation_id', 'idempotency_key'],
                        'mfg_log_operation_idem_uq'
                    );
                });
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'manufacturing_idempotency_keys',
            'manufacturing_cost_entries',
            'manufacturing_stock_movements',
            'quality_inspections',
            'manufacturing_operation_logs',
            'manufacturing_equipment',
            'manufacturing_operations',
            'manufacturing_order_materials',
            'manufacturing_order_status_histories',
            'manufacturing_orders',
            'bom_items',
            'bom_versions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
