<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إشارات أداء التوصيات (Phase 6 / ADR-045): ظهور/نقر/تحويل — للوحات KPI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('recommended_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('type', 30)->default('related');
            $table->string('event', 12)->default('impression'); // impression | click | conversion
            $table->string('source', 20)->default('rule');       // rule | manual | order_history | trending
            $table->string('placement', 20)->nullable();          // product | cart | checkout
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('session_token', 80)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['event', 'created_at']);
            $table->index(['recommended_product_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_events');
    }
};
