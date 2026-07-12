<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بنود جرد المخزون (Phase 5 / ADR-043). لقطة النظام مقابل العدّ الفعلي والفرق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained('inventory_counts')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('system_qty', 15, 3)->default(0);   // لقطة on_hand عند الفتح
            $table->decimal('counted_qty', 15, 3)->nullable();   // العدّ الفعلي
            $table->decimal('variance', 15, 3)->default(0);      // counted − system
            $table->boolean('applied')->default(false);          // طُبّقت تسويته
            $table->string('note', 255)->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();

            $table->unique(['inventory_count_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_items');
    }
};
