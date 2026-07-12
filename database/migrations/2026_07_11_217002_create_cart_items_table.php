<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// بنود السلة (ADR-031). سعر البند لقطة من الكتالوج وقت الإضافة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty', 15, 3);
            $table->decimal('unit_price', 15, 2); // لقطة سعر الكتالوج (promo أو retail)
            $table->timestamps();

            $table->unique(['cart_id', 'variant_id']); // بند واحد لكل متغيّر
            $table->index('cart_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
