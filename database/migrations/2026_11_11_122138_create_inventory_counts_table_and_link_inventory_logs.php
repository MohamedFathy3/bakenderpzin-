<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) جدول هيدر الجرد (مستند واحد لكل عملية جرد)
        Schema::create('inventory_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->text('note')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        // 2) ربط سطور الجرد (inventory_logs) بالهيدر
        Schema::table('inventory_logs', function (Blueprint $t) {
            $t->foreignId('inventory_count_id')->nullable()->after('id')
                ->constrained('inventory_counts')->nullOnDelete();
        });

        // 3) ترحيل الجرد القديم: كل (مخزن + دقيقة) يبقى مستند واحد
        $groups = DB::table('inventory_logs')
            ->selectRaw("warehouse_id, DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') as m, MIN(created_at) as c, MAX(note) as note")
            ->groupBy('warehouse_id', 'm')
            ->get();

        foreach ($groups as $g) {
            $id = DB::table('inventory_counts')->insertGetId([
                'warehouse_id' => $g->warehouse_id,
                'note'         => $g->note,
                'created_at'   => $g->c,
                'updated_at'   => $g->c,
            ]);

            DB::table('inventory_logs')
                ->where('warehouse_id', $g->warehouse_id)
                ->whereRaw("DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') = ?", [$g->m])
                ->update(['inventory_count_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('inventory_logs', function (Blueprint $t) {
            $t->dropConstrainedForeignId('inventory_count_id');
        });

        Schema::dropIfExists('inventory_counts');
    }
};