<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_transfers', function (Blueprint $table) {
            $table->string('transfer_number', 40)->nullable()->unique()->after('id');
            $table->dateTime('transfer_date')->nullable()->after('created_by');
        });

        DB::table('warehouse_transfers')
            ->orderBy('id')
            ->eachById(function ($transfer) {
                $date = $transfer->created_at ?: now();
                DB::table('warehouse_transfers')
                    ->where('id', $transfer->id)
                    ->update([
                        'transfer_number' => 'WHS-' . date('Ymd', strtotime($date)) . '-' . str_pad((string) $transfer->id, 6, '0', STR_PAD_LEFT),
                        'transfer_date' => $date,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('warehouse_transfers', function (Blueprint $table) {
            $table->dropUnique(['transfer_number']);
            $table->dropColumn(['transfer_number', 'transfer_date']);
        });
    }
};
