<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ استخدام مساعد المحتوى بالذكاء الاصطناعي (Phase 6 / ADR-044).
 * يسجّل المستخدم/النوع/النموذج/التوكِنات/الحالة — **بلا أسرار ولا نصّ مطالبة كامل**.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('type', 40);       // title_ar/description/seo_title/...
            $table->string('action', 20);     // generate/regenerate/shorten/expand/tone
            $table->string('locale', 5)->default('ar');
            $table->string('provider', 30)->default('null');
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->string('status', 20)->default('success'); // success/failed/timeout/fallback
            $table->string('error', 300)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generation_logs');
    }
};
