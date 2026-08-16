<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قنوات الإعلان — صفحات البيع التي يُصرَف عليها إعلانيًّا.
 *
 * وحدة قرار الإنفاق ليست الصنف وحده بل **الصنف على صفحة بعينها**: الصنف نفسه
 * قد يربح على صفحة ويخسر على أخرى (جمهور ومحتوى ومزايدة مختلفة)، وجمعُهما في
 * رقم واحد يُخفي الخسارة داخل متوسّط.
 *
 * والقناة تُستنتج من الطلب بلا إدخال يدوي: لكل صفحة حساب «بزنس» مستقلّ لدى شركة
 * التوصيل، والموظفة مرتبطة بحسابها — فالربط هنا بـ`delivery_business_id`.
 * الجدول مستقلّ عن `delivery_businesses` عمدًا: ذاك يُزامَن من المزوّد ولا يُمسّ.
 */
return new class extends Migration
{
    /** الصفحات القائمة — تُنشأ جاهزة ليبقى على المستخدم ربطُ كل واحدة بحسابها. */
    private const CHANNELS = [
        ['توفير اون لاين', 'facebook', 1],
        ['شاهين اليت هوم', 'facebook', 2],
        ['شاهين اكسبريس', 'facebook', 3],
        ['جاردن هوم', 'facebook', 4],
    ];

    public function up(): void
    {
        Schema::create('ad_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('platform', 30)->default('facebook');
            // فريد: حسابُ بزنسٍ واحد لا يخدم صفحتين، وإلّا انهار الإسناد الآلي.
            $table->foreignId('delivery_business_id')->nullable()->unique()
                ->constrained('delivery_businesses')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        foreach (self::CHANNELS as [$name, $platform, $sort]) {
            DB::table('ad_channels')->insert([
                'name' => $name,
                'platform' => $platform,
                'is_active' => true,
                'sort_order' => $sort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_channels');
    }
};
