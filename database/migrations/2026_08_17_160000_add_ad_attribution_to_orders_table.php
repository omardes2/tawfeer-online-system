<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نسبة الطلب الإلكتروني إلى الإعلان الذي جاء منه.
 *
 * `orders.ad_channel_id` القائمة تُشتقّ من **الموظفة التي أدخلت الطلب** — وهي
 * السلسلة الصحيحة لطلبات الرسائل. أمّا الطلب القادم من الموقع فلا موظّف له،
 * فيسقط بلا قناة ويظهر في «الميزانية اليومية» تحت «غير منسوب». وبدون هذه
 * الأعمدة لا يمكن لأي نظام — طيّارًا كان أو إنسانًا — أن يعرف أيّ إعلانٍ أنتج
 * أيّ طلب، فلا حكم على حملة مبيعاتٍ ولا إيقاف لخاسرة.
 *
 * والمفتاح العملي هو `ad_set_ref`: المنصّة لا تُخبرك بالحملة من `fbclid`، لكنها
 * تسمح بمعاملاتٍ ديناميكية في رابط الإعلان — فيُوضَع معرّف المجموعة الإعلانية
 * في `utm_content` ويصل إلينا مع الزيارة، ومنه نعرف الصنف والصفحة عبر
 * `ad_external_maps`. أمّا `fbclid` فيُحفظ لأن Conversions API تحتاجه لمطابقة
 * الشراء بالنقرة (وهي مطابقةٌ أدقّ من البريد والهاتف).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // معرّف نقرة الإعلان — لمطابقة Conversions API لا للنسبة.
            $table->string('ad_click_id', 255)->nullable()->after('ad_channel_id');
            $table->string('ad_source', 40)->nullable()->after('ad_click_id');
            $table->string('ad_campaign_ref', 64)->nullable()->after('ad_source');
            // معرّف المجموعة الإعلانية — عليه وحده تقوم النسبة إلى صنفٍ وصفحة.
            $table->string('ad_set_ref', 64)->nullable()->after('ad_campaign_ref');

            $table->index('ad_set_ref', 'orders_ad_set_ref_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_ad_set_ref_index');
            $table->dropColumn(['ad_click_id', 'ad_source', 'ad_campaign_ref', 'ad_set_ref']);
        });
    }
};
