<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_items', 'unit_id')) {
                $table->unsignedBigInteger('unit_id')->nullable()->after('size')->index();
            }

            if (!Schema::hasColumn('invoice_items', 'color_id')) {
                $table->unsignedBigInteger('color_id')->nullable()->after('unit_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            foreach (['unit_id', 'color_id'] as $column) {
                if (Schema::hasColumn('invoice_items', $column)) {
                    $table->dropIndex([$column]);
                    $table->dropColumn($column);
                }
            }
        });
    }
};
