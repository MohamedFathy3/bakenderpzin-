<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) جدول عدادات يومية لرقم التحويل (WHS-DDMMYYYY-N)
        if (!Schema::hasTable('warehouse_transfer_sequences')) {
            Schema::create('warehouse_transfer_sequences', function (Blueprint $table) {
                $table->string('date_key', 8)->primary(); // dmY = 08112026
                $table->unsignedInteger('last_number')->default(0);
                $table->timestamps();
            });
        }

        // 2) جدول تفاصيل التحويل (زي InventoryLog للجرد)
        if (!Schema::hasTable('warehouse_transfer_items')) {
            Schema::create('warehouse_transfer_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('warehouse_transfer_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('product_unit_id')->nullable();
                $table->unsignedBigInteger('color_id')->nullable();
                $table->integer('quantity');

                // تتبع الرصيد قبل/بعد لكل طرف (زي system_stock/counted_stock في الجرد)
                $table->integer('source_before')->default(0);
                $table->integer('source_after')->default(0);
                $table->integer('destination_before')->default(0);
                $table->integer('destination_after')->default(0);

                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->decimal('total_cost', 15, 2)->default(0);
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index('warehouse_transfer_id', 'wti_transfer_idx');
                $table->index(['product_id', 'product_unit_id', 'color_id'], 'wti_variant_idx');
                $table->foreign('warehouse_transfer_id')
                    ->references('id')
                    ->on('warehouse_transfers')
                    ->onDelete('cascade');
            });
        }

        // 3) إعادة ترقيم التحويلات القديمة إلى WHS-DDMMYYYY-N
        //    (من WHS-20241109-000123 → WHS-09112024-1) — chunkById آمن للذاكرة
        if (Schema::hasColumn('warehouse_transfers', 'transfer_number')) {
            $dailyCounters = [];

            DB::table('warehouse_transfers')
                ->chunkById(500, function ($transfers) use (&$dailyCounters) {
                    foreach ($transfers as $transfer) {
                        $date = $transfer->transfer_date
                            ?: $transfer->created_at
                            ?: now();
                        $dateKey = date('dmY', strtotime($date));

                        $dailyCounters[$dateKey] = ($dailyCounters[$dateKey] ?? 0) + 1;

                        DB::table('warehouse_transfers')
                            ->where('id', $transfer->id)
                            ->update([
                                'transfer_number' => "WHS-{$dateKey}-{$dailyCounters[$dateKey]}",
                            ]);
                    }
                });

            // 4) تهيئة جدول العدادات بالحد الأقصى لكل يوم
            foreach ($dailyCounters as $dateKey => $maxSequence) {
                DB::table('warehouse_transfer_sequences')->updateOrInsert(
                    ['date_key' => $dateKey],
                    [
                        'last_number' => $maxSequence,
                        'updated_at'  => now(),
                        'created_at'  => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_transfer_items');
        Schema::dropIfExists('warehouse_transfer_sequences');
    }
};