<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('products', function(Blueprint $t) {
  if (!Schema::hasColumn('products','product_type')) $t->string('product_type')->default('good')->index();
  if (!Schema::hasColumn('products','stock_unit')) $t->string('stock_unit')->nullable();
  if (!Schema::hasColumn('products','base_unit')) $t->string('base_unit')->nullable();
  if (!Schema::hasColumn('products','conversion_factor')) $t->decimal('conversion_factor',18,6)->default(1);
  if (!Schema::hasColumn('products','tracking')) $t->string('tracking')->default('none');
  if (!Schema::hasColumn('products','dimensions')) $t->json('dimensions')->nullable();
 }); }
 public function down(): void { Schema::table('products', function(Blueprint $t) { foreach(['product_type','stock_unit','base_unit','conversion_factor','tracking','dimensions'] as $c) if(Schema::hasColumn('products',$c)) $t->dropColumn($c); }); }
};
