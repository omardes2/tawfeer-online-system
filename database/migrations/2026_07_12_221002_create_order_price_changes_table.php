<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ تغييرات سعر البيع اليدوي (Phase 4.1 / ADR-037) — **غير قابل للتعديل** (append-only،
 * لا حذف — المبدأ 8). يوثّق كل تعديل سعر وحالة اعتماده (BR-EMP-05/BR-PRICE-06).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('original_retail', 15, 2);
            $table->decimal('wholesale_cost', 15, 2)->default(0);
            $table->decimal('min_price', 15, 2)->default(0);
            $table->decimal('new_price', 15, 2);
            $table->decimal('discount', 15, 2)->default(0);
            $table->string('reason', 255)->nullable();
            $table->boolean('requires_approval')->default(false);
            // auto_approved / pending / approved / rejected
            $table->string('status', 20)->default('auto_approved');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_price_changes');
    }
};
