<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط معرّفات المنصّة الخارجية بكيانات النظام.
 *
 * المنصّة تعطي `adset_id` واسمًا نصّيًا، ولا تعرف أن «مكنسة كليكي» هي الصنف 47.
 * والمطابقة بالاسم كل ليلة تنكسر عند أول إعادة تسمية أو اختلاف همزة — فيضيع
 * صرفُ يومٍ بصمت ويبدو الصنف أربح ممّا هو.
 *
 * فالربط يُحفَظ **بالمعرّف** مرّة واحدة: الاسم يتغيّر والمعرّف لا يتغيّر. ويبقى
 * `external_name` مخزَّنًا للعرض وحده (آخر اسمٍ رأيناه)، لا للمطابقة.
 *
 * والاقتراح لا يربط: المزامنة تُسجّل الجديد غير مربوط مع مرشَّحٍ مقترح بالاسم،
 * ويؤكّده المستخدم. ربطُ صنفٍ خطأً يَنسب صرفًا إلى صنفٍ لم يُعلَن عليه، وهو خطأ
 * لا يظهر في أي رقم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_external_maps', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->default('meta');
            $table->string('external_type', 20);   // campaign | adset
            $table->string('external_id', 64);
            $table->string('external_name', 255)->nullable();

            // الحملة تُربَط بقناة، والمجموعة الإعلانية بصنف — عمودٌ لكلٍّ منهما،
            // ويبقى الآخر فارغًا. جدولان منفصلان كانا سيُكرّران المنطق نفسه.
            $table->foreignId('ad_channel_id')->nullable()->constrained('ad_channels')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            // مرشَّح مقترح بالاسم — للعرض والتأكيد بنقرة، لا يُستعمل في الحساب.
            $table->foreignId('suggested_ad_channel_id')->nullable()->constrained('ad_channels')->nullOnDelete();
            $table->foreignId('suggested_product_id')->nullable()->constrained('products')->nullOnDelete();

            // مجموعة إعلانية لا تخصّ صنفًا (اختبار، وعي بالعلامة): تُتجاهَل عمدًا
            // فلا تبقى في قائمة «غير مربوط» تُقلق بلا سبب.
            $table->boolean('is_ignored')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_type', 'external_id'], 'ad_external_maps_unique');
            $table->index(['provider', 'external_type', 'is_ignored'], 'ad_external_maps_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_external_maps');
    }
};
