<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حقول التوصيل على الطلب (نمط Opost): المدينة والمنطقة المُعيَّنتان (لا اسم — المتطلّب 9)،
 * وجود مرتجع وملاحظاته، ولقطة تتبّع المزوّد (رقم التتبّع/المعرّف الخارجي/الحالة الخام).
 * تُلتقط عند الإنشاء وتُستكمل عند تأكيد الطلب (إرسال لشركة التوصيل).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('shipping_address')->constrained('cities')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->after('city_id')->constrained('areas')->nullOnDelete();
            $table->boolean('has_return')->default(false)->after('area_id');
            $table->text('return_notes')->nullable()->after('has_return');
            // لقطة تتبّع مزوّد التوصيل (تُملأ عند التأكيد/الإرسال).
            $table->string('tracking_number', 120)->nullable()->after('return_notes');
            $table->string('delivery_external_id', 80)->nullable()->after('tracking_number');
            $table->string('delivery_status', 40)->nullable()->after('delivery_external_id');

            $table->index('city_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
            $table->dropConstrainedForeignId('area_id');
            $table->dropColumn(['has_return', 'return_notes', 'tracking_number', 'delivery_external_id', 'delivery_status']);
        });
    }
};
