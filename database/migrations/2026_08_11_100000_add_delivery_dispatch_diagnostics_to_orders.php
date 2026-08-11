<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تشخيص إرسال الطلب لشركة التوصيل: سبب آخر فشل وعدد المحاولات ووقتها.
 * كان سبب الفشل يُكتب في سجلّ الأخطاء فقط، فيظهر الطلب «بانتظار التتبّع» بلا تفسير.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_dispatch_error', 500)->nullable()->after('tracking_number');
            $table->unsignedSmallInteger('delivery_dispatch_attempts')->default(0)->after('delivery_dispatch_error');
            $table->timestamp('delivery_dispatch_attempted_at')->nullable()->after('delivery_dispatch_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_dispatch_error', 'delivery_dispatch_attempts', 'delivery_dispatch_attempted_at']);
        });
    }
};
