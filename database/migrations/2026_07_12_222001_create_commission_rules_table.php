<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قواعد العمولة/الأرباح (Phase 4.2 / ADR-037). قابلة للإعداد حسب نطاق (موظف/حملة/
 * منتج/فئة/فرع/دور/فترة) بأولويّة حتمية. الافتراضي 1% للمبيعات، والهامش الكامل للمسوّق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('earner_type', 20);       // sales / affiliate
            $table->string('method', 20);            // percent / fixed / margin
            $table->decimal('rate', 8, 6)->nullable();       // 0.01 = 1%
            $table->decimal('amount', 15, 2)->nullable();    // للمبلغ الثابت

            // النطاق (كلّه اختياري؛ الأولويّة تُشتقّ من أخصّ بُعد مضبوط).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('campaign', 60)->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('role', 40)->nullable();

            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->boolean('include_shipping')->default(false);
            $table->unsignedSmallInteger('priority')->default(1); // مشتقّة من النطاق
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['earner_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
