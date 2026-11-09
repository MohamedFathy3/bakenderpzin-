<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('product_unit_id')->nullable()->after('product_id');
            $table->unsignedBigInteger('color_id')->nullable()->after('product_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropColumn(['product_unit_id', 'color_id']);
        });
    }
};