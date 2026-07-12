<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفتر العمولات/الأرباح (Phase 4.2 / ADR-037) — **غير قابل للتعديل** (append-only).
 * كل صف حركة: accrual (استحقاق) / adjustment (تعديل تناسبي) / reversal (عكس). المبالغ
 * لا تُعدَّل — الصافي مشتقّ من مجموع الحركات، لا عمود رصيد. لا حذف — المبدأ 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('earner_type', 20);        // sales / affiliate
            $table->foreignId('earner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            $table->string('entry_type', 20)->default('accrual'); // accrual / adjustment / reversal
            $table->decimal('basis', 15, 2)->default(0);          // الأساس (صافي البند / الهامش)
            $table->decimal('rate', 8, 6)->nullable();
            $table->decimal('amount', 15, 2);                     // القيمة (بإشارة: عكس/تعديل قد يكون سالبًا)
            $table->decimal('wholesale_cost_snapshot', 15, 2)->nullable(); // للمسوّق

            $table->foreignId('rule_id')->nullable()->constrained('commission_rules')->nullOnDelete();
            $table->json('rule_snapshot')->nullable();            // لقطة القاعدة المطبَّقة

            // pending / eligible / approved / paid / reversed / cancelled
            $table->string('state', 20)->default('pending');
            $table->foreignId('reverses_entry_id')->nullable()->constrained('commission_entries')->nullOnDelete();
            $table->foreignId('adjusts_entry_id')->nullable()->constrained('commission_entries')->nullOnDelete();
            $table->string('settlement_reference', 80)->nullable(); // يُضبط عند الاستحقاق (4.6)

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['earner_type', 'earner_id', 'state']);
            $table->index(['order_id', 'entry_type']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_entries');
    }
};
