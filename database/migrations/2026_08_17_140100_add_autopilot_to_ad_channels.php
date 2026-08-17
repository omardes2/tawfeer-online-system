<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تفعيل الطيّار الآلي **لكل صفحة على حدة**.
 *
 * لا مفتاحٌ واحد يحكم الصفحات الأربع: صاحب العمل يجرّب الأتمتة على صفحةٍ يعرف
 * أرقامها ويبقي الباقي بيده. والافتراض `false` لكلّها — الأتمتة تُفتح بقرارٍ لا
 * تُورَث من ترحيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_channels', function (Blueprint $table) {
            $table->boolean('autopilot_enabled')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('ad_channels', function (Blueprint $table) {
            $table->dropColumn('autopilot_enabled');
        });
    }
};
