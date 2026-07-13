<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توصيات المنتجات اليدوية والاستثناءات (Phase 6 / ADR-045). يعمل مع المحرّك القائم على القواعد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('recommended_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('type', 30)->default('related'); // related/cross_sell/upsell/complementary/fbt
            $table->string('kind', 12)->default('include');  // include | exclude
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['product_id', 'recommended_product_id', 'type'],
                'product_recommendation_unique'
            );
            $table->index(['product_id', 'type', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_recommendations');
    }
};
