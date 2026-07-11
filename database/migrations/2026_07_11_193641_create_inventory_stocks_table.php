<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// أرصدة المخزون بالدلاء لكل (متغيّر × مستودع) — PHASE_2_DESIGN §22، ADR-007.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('on_hand', 15, 3)->default(0);
            $table->decimal('reserved', 15, 3)->default(0);
            $table->decimal('damaged', 15, 3)->default(0);
            $table->decimal('returned_pending', 15, 3)->default(0);
            $table->decimal('in_transit', 15, 3)->default(0);
            $table->decimal('average_cost', 15, 4)->default(0);
            $table->decimal('cost_price', 15, 4)->default(0);
            $table->decimal('reorder_level', 15, 3)->nullable();
            $table->decimal('reorder_qty', 15, 3)->nullable();
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();

            $table->unique(['variant_id', 'warehouse_id']);
            $table->index('warehouse_id');
            $table->index(['warehouse_id', 'on_hand']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stocks');
    }
};
