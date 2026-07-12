<?php

use App\Modules\Shipping\Support\DeliveryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دورة حياة التوصيل القانونية (ADR-038) — إضافية فوق شحنات 2.7 دون إعادة تصميم.
 * `delivery_status` (قانوني داخلي) منفصل تمامًا عن `provider_status` (خام من المزوّد).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // الحالة القانونية الداخلية (تقرأها وحدات الأعمال).
            $table->string('delivery_status', 40)->default(DeliveryStatus::INITIAL)->after('status')->index();
            // حالة المزوّد الخام (لا تقرأها وحدات الأعمال — للعرض/التتبّع فقط).
            $table->string('provider_status', 60)->nullable()->after('delivery_status');
            // لقطة سبب التعليق الحالي (مصنّف — للفلترة/التقارير السريعة).
            $table->string('on_hold_reason', 40)->nullable()->after('provider_status');
            // ختم الإغلاق المالي النهائي.
            $table->timestamp('closed_at')->nullable()->after('on_hold_reason');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['delivery_status', 'provider_status', 'on_hold_reason', 'closed_at']);
        });
    }
};
