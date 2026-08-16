<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لقطة قناة الإعلان على الطلب — تُثبَّت لحظة الإنشاء ولا تُشتقّ لاحقًا.
 *
 * القناة معروفة من منشئ الطلب (موظفة → حساب بزنس → صفحة)، فكان يمكن استنتاجها
 * وقت العرض. لكنّ الاستنتاج يقرأ الحاضر: نقلُ موظفةٍ إلى صفحة أخرى غدًا كان
 * ينقل معها **كل طلباتها السابقة**، فيتغيّر تقرير الشهر الماضي بصمت ويُنسَب
 * صرفُ صفحةٍ إلى مبيعات أخرى. اللقطة تجمّد التاريخ عند وقوعه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('ad_channel_id')->nullable()->after('channel')
                ->constrained('ad_channels')->nullOnDelete();
            // الاستعلام الأساسي في الصفحة: تجميع بالقناة ضمن مدًى زمني.
            $table->index(['ad_channel_id', 'created_at'], 'orders_ad_channel_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_ad_channel_created_index');
            $table->dropConstrainedForeignId('ad_channel_id');
        });
    }
};
