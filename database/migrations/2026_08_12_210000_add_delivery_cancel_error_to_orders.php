<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أثر فشل إلغاء الشحنة لدى المزوّد. كان الفشل يُبتلع صمتًا فيبقى الطرد نشطًا لدى شركة
 * التوصيل رغم إلغاء الطلب عندنا — فيصل العميل بضاعةً لطلبٍ ملغى. العمود يجعل الفشل
 * مرئيًّا وقابلًا لإعادة المحاولة تلقائيًا (shipping:cancel-pending).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || Schema::hasColumn('orders', 'delivery_cancel_error')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_cancel_error', 500)->nullable()->after('delivery_dispatch_error');
            $table->timestamp('delivery_cancel_attempted_at')->nullable()->after('delivery_cancel_error');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'delivery_cancel_error')) {
            Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['delivery_cancel_error', 'delivery_cancel_attempted_at']));
        }
    }
};
