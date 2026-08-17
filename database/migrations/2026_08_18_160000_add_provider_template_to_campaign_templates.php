<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * القالب المعتمَد لدى المنصّة — بجانب نصّ القالب عندنا.
 *
 * النصّ الذي نكتبه هنا **لا يُرسَل تسويقيًّا**. خارج نافذة الأربع والعشرين ساعة
 * ترفض المنصّة كل نصٍّ حرّ وتقبل قالبًا اعتمدته هي مسبقًا باسمٍ ولغة. فمن يبني
 * حملةً على النصّ وحده يجدها تفشل بالكامل عند أول إرسالٍ حقيقي — بعد أن يكون
 * بنى القائمة والحملة والتوقيت.
 *
 * فيبقى النصّ عندنا للمعاينة والسجلّ، ويُضاف إليه **اسم القالب المعتمَد** الذي
 * يُرسَل فعلًا. و`provider_params` يصف ترتيب متغيّراته: المنصّة ترقّمها {{1}}
 * و{{2}} لا تُسمّيها، واختلاف الترتيب يضع اسم الزبون مكان اسم الصنف بلا خطأٍ
 * يُرفَع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_templates', function (Blueprint $table) {
            $table->string('provider_template', 120)->nullable()->after('channel');
            $table->string('provider_language', 10)->nullable()->after('provider_template');
            // ترتيب المتغيّرات: ["customer_name","product"] ⇐ {{1}}، {{2}}
            $table->json('provider_params')->nullable()->after('provider_language');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_templates', function (Blueprint $table) {
            $table->dropColumn(['provider_template', 'provider_language', 'provider_params']);
        });
    }
};
