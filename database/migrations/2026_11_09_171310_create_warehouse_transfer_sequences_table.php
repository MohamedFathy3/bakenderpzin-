<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) إنشاء جدول العدادات
        if (!Schema::hasTable('warehouse_transfer_sequences')) {
            Schema::create('warehouse_transfer_sequences', function (Blueprint $table) {
                $table->string('date_key', 8)->primary(); // dmY = 09112026
                $table->unsignedInteger('last_number')->default(0);
                $table->timestamps();
            });
        }

        // 2) تعبئة العدادات من التحويلات الموجودة بالشكل الجديد WHS-DDMMYYYY-N
        //    (يتخطى التحويلات القديمة WHS-20241109-000123)
        if (Schema::hasColumn('warehouse_transfers', 'transfer_number')) {
            $rows = DB::table('warehouse_transfers')
                ->whereNotNull('transfer_number')
                ->where('transfer_number', 'LIKE', 'WHS-%')
                ->get(['transfer_number']);

            $counters = [];
            foreach ($rows as $row) {
                // نلتقط الشكل الجديد فقط: WHS-{8 digits}-{N}
                if (preg_match('/^WHS-(\d{8})-(\d+)$/', $row->transfer_number, $m)) {
                    $dateKey = $m[1];      // 09112026
                    $seq     = (int) $m[2]; // 3

                    $counters[$dateKey] = max($counters[$dateKey] ?? 0, $seq);
                }
            }

            foreach ($counters as $dateKey => $maxSeq) {
                DB::table('warehouse_transfer_sequences')->updateOrInsert(
                    ['date_key' => $dateKey],
                    [
                        'last_number' => $maxSeq,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_transfer_sequences');
    }
};