<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الصرف الإعلاني اليومي لكل (صنف × قناة) — يُنسَخ يدويًّا من مدير إعلانات Meta.
 *
 * بنية الحساب الإعلاني تعطي هذين البعدين جاهزين: الحملة = الصفحة، والمجموعة
 * الإعلانية = الصنف بميزانيتها المستقلّة. فالصفّ هنا يقابل صفًّا هناك، ويُنسَخ
 * منه رقمان: «المبلغ الذي تم إنفاقه» و«النتائج» (محادثات تم بدؤها).
 *
 * وعدد المحادثات ليس زينة: هو ما يفصل فشل الإعلان عن فشل البيع. محادثات كثيرة
 * رخيصة بلا طلبات تعني خللًا في الردّ أو السعر أو التوفّر — لا في الإعلان،
 * وإيقافُه حينها خطأ. ومحادثاتٌ قليلة غالية تعني العكس.
 *
 * `fx_rate` مخزَّن لا محسوب: الصرف بالدولار والمبيعات بالشيكل، والإدخال يجري في
 * اليوم التالي — فلو حُوِّل بسعر يوم الإدخال لتحرّك ربحُ الأمس مع سعر الصرف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_daily_spends', function (Blueprint $table) {
            $table->id();
            $table->date('spend_date');
            $table->foreignId('ad_channel_id')->constrained('ad_channels')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('amount_usd', 15, 2)->default(0);
            $table->decimal('fx_rate', 12, 4);
            $table->unsignedInteger('conversations')->default(0);
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // صفٌّ واحد لا غير لكل (يوم، قناة، صنف): الإدخال المتأخّر يُعاد مرارًا
            // حتى يستقرّ رقم Meta، فيجب أن يُحدِّث لا أن يتراكم.
            $table->unique(['spend_date', 'ad_channel_id', 'product_id'], 'ad_daily_spends_unique');
            $table->index('spend_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_daily_spends');
    }
};
