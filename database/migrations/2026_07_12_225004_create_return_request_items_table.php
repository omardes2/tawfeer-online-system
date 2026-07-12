<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بنود طلب المرتجع (Phase 4.4 / ADR-040). لقطة سعر/تكلفة + نتيجة الفحص وتوجيه المخزون.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained('return_requests')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty', 15, 3);
            $table->string('reason_code', 40)->nullable();
            // نتيجة الفحص: sellable | damaged | wrong_item | missing_parts | quarantine
            $table->string('inspection_result', 20)->nullable();
            // توجيه المخزون: restock | damaged | none
            $table->string('inventory_route', 12)->nullable();
            $table->boolean('restocked')->default(false);
            // الاستبدال (عند type=exchange/replacement).
            $table->foreignId('exchange_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('exchange_qty', 15, 3)->nullable();
            $table->decimal('price_difference', 15, 2)->default(0);
            // لقطات (تُنسخ من بند الطلب — تُثبّت أساس العكس).
            $table->decimal('unit_price_snapshot', 15, 2)->default(0);
            $table->decimal('wholesale_cost_snapshot', 15, 2)->nullable();
            $table->timestamps();

            $table->index('return_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_request_items');
    }
};
