<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// بنود أمر الشراء (DATABASE_DESIGN §4).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty_ordered', 15, 3);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->decimal('qty_received', 15, 3)->default(0);
            $table->timestamps();

            $table->index(['purchase_order_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
