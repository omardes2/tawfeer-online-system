<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المعرفة البيعية للصنف — ما يقوله البائع لا ما يقوله الكتالوج.
 *
 * المواصفات في `products`؛ وهذه **الفائدة**: لمن يناسب، وبمَ يُردّ على اعتراضه،
 * وكيف يُقارَن بغيره. وبلا هذا الجدول يرتجل الوكيل — وارتجالُه في الاعتراض هو
 * بالضبط ما يُفقد الثقة.
 *
 * و`is_ready` حارسٌ لا زينة: صنفٌ لم يُكتب له بيعٌ بعد لا يبيعه الوكيل، بل
 * يحوّل إلى موظفة. فالصمت أفضل من كلامٍ مخترَع باسم الشركة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_knowledge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->json('selling_points')->nullable();   // الفائدة لا المواصفة
            $table->json('use_cases')->nullable();        // لمن يناسب / لمن لا يناسب
            $table->json('objections')->nullable();       // [{objection, response}]
            $table->json('faq')->nullable();              // [{question, answer}] بالعامية
            $table->json('comparisons')->nullable();      // [{product_id, difference}]
            $table->text('tone_notes')->nullable();
            $table->boolean('is_ready')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('is_ready');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_knowledge');
    }
};
