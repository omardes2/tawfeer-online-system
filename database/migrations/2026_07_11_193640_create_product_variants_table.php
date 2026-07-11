<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// متغيّرات المنتج (PHASE_2_DESIGN §17) — أساس المخزون (ADR-024).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 60)->unique();
            $table->string('barcode', 60)->nullable();
            $table->string('name', 200)->nullable();
            $table->decimal('cost_price', 15, 4)->default(0);
            $table->decimal('average_cost', 15, 4)->default(0);
            $table->decimal('retail_price', 15, 2)->default(0);
            $table->decimal('wholesale_price', 15, 2)->nullable();
            $table->decimal('marketer_price', 15, 2)->nullable();
            $table->decimal('min_price', 15, 2)->nullable();
            $table->decimal('promo_price', 15, 2)->nullable();
            $table->decimal('weight', 15, 3)->nullable();
            $table->decimal('reorder_level', 15, 3)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('product_id');
            $table->index(['product_id', 'is_default']);
            $table->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
