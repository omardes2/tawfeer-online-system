<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قنوات المراسلة المربوطة (الصندوق الموحّد — المتطلّب 2.9).
 *
 * `messaging_channels` لا `channels`: الاسم المجرّد يلتبس بـ`ad_channels`
 * (صفحات الإعلان) وبـ`channel` على الطلب (مصدر الطلب)، وثلاثتها مفاهيم مختلفة
 * في نظامٍ واحد.
 *
 * و`ai_enabled` مفتاح إطفاءٍ على مستوى القناة: إيقاف الوكيل قرارٌ إداريّ يجب
 * أن يُنفَّذ من اللوحة في ثانية، لا بنشر كودٍ أو تعديل `.env`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_channels', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('provider', 20);              // whatsapp | messenger | instagram
            $table->string('name', 100);
            $table->string('external_id', 100);          // phone_number_id لواتساب
            $table->string('waba_id', 100)->nullable();
            // بيانات الاعتماد مشفّرة في العمود: القناة تُدار من اللوحة، فلا مكان
            // لها في `.env` — والتشفير يمنع قراءتها من نسخةٍ احتياطية مسرّبة.
            $table->text('credentials')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('ai_enabled')->default(false);
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_channels');
    }
};
