<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// بنود طلب البيع (ADR-026). تجميد سعر البند (BR-ORD-18). بلا soft-delete (تابعة للرأس).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty', 15, 3);
            $table->decimal('unit_price', 15, 2);           // لقطة سعر (BR-ORD-18)
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);  // مؤجّل (ADR-015)
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->decimal('qty_reserved', 15, 3)->default(0);
            $table->decimal('qty_shipped', 15, 3)->default(0);
            $table->timestamps();

            $table->index('order_id');
            $table->index('variant_id');
            $table->unique(['order_id', 'variant_id']); // بند واحد لكل متغيّر في الطلب
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
