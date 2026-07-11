<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ربط المنتجات بالوسوم M:N (PHASE_2_DESIGN §21).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_tag_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'product_tag_id']);
            $table->index('product_tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_tag_links');
    }
};
