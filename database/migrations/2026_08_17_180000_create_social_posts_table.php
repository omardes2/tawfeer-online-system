<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * منشورات فيسبوك وإنستغرام — مساحة عملٍ لا مولّد نصوصٍ عابر.
 *
 * التوليد وحده كان يكفي لو كان المنشور يُكتب مرّة ويُنسى. لكنه يُعاد ويُعدَّل
 * ويُقارَن بما سبقه: أيّ صنفٍ نُشر له ومتى، وما الذي لم يُنشر منذ شهر، وما
 * النصّ الذي أُطلق فعلًا مقابل ما بقي مسوّدة. وبلا جدولٍ يبقى ذلك كلّه في ذاكرة
 * صاحب العمل — فيُعاد نشر الصنف نفسه مرّتين ويُنسى غيره.
 *
 * و`ai_model`/`ai_status` ليسا زينة: نصٌّ ولّده نموذجٌ احتياطي بعد فشل المزوّد
 * يختلف في جودته عمّا ولّده النموذج المقصود، ومن يقرأ المنشور بعد شهر يحتاج
 * أن يعرف أيّهما كان — كما يُعرَف مصدر التقييم المستورَد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            // الصفحة المقصودة — ومنها يُبنى الرابط المتتبَّع فتُنسب طلباتُه إليها.
            $table->foreignId('ad_channel_id')->nullable()->constrained('ad_channels')->nullOnDelete();

            $table->string('platform', 20)->default('facebook'); // facebook | instagram | both
            $table->string('locale', 5)->default('ar');
            $table->string('tone', 30)->nullable();

            $table->text('body');
            $table->text('hashtags')->nullable();
            // الرابط المتتبَّع كما نُشر — يُحفَظ ولا يُعاد بناؤه: تغيّرُ الإعدادات
            // لاحقًا كان سيُظهر رابطًا غير الذي وصل الزبائن فعلًا.
            $table->string('link', 500)->nullable();

            $table->string('status', 20)->default('draft'); // draft | ready | published
            $table->timestamp('published_at')->nullable();

            // مصدر النصّ — نموذجٌ بعينه أم احتياطي أم كتابة إنسان.
            $table->string('ai_model', 60)->nullable();
            $table->string('ai_status', 20)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'social_posts_status_index');
            $table->index(['product_id', 'created_at'], 'social_posts_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
