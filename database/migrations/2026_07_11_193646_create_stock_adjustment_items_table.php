<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// بنود تسوية الجرد (PHASE_2_DESIGN §26 — جدول البنود).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty_before', 15, 3)->default(0);
            $table->decimal('qty_counted', 15, 3)->default(0);
            $table->decimal('qty_diff', 15, 3)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['stock_adjustment_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
    }
};
