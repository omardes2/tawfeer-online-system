<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ربط المنتجات بالسمات المطبّقة عليها M:N (PHASE_2_DESIGN §18).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attribute_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('product_attributes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'attribute_id']);
            $table->index('attribute_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_links');
    }
};
