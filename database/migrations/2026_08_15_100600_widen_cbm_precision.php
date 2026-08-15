<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توسيع دقّة الحجم إلى ستّ منازل.
 *
 * أربعُ منازل كافيةٌ لصنفٍ كبير، لا لقطعةٍ صغيرة: جهازٌ حجمُه 0.00531 م³ يُقرَّب
 * إلى 0.0053، و0.00111111 إلى 0.0011 — خطأٌ يبلغ 1٪ في نصيب الصنف من الشحن
 * البحري، ويتراكم على آلاف القطع في الكونتينر. والأسوأ أن المتصفّح كان يرفض
 * القيمة أصلًا (`step=0.0001`) فيتعذّر إدخالها.
 *
 * ستّ منازل = دقّة ملليلتر واحد (0.000001 م³ = 1 سم³)، وهي أدقّ ممّا يُقاس به
 * كرتونٌ فعلًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cbm', 12, 6)->nullable()->change();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('cbm', 12, 6)->nullable()->change();
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->decimal('cbm_per_unit', 12, 6)->default(0)->change();
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            // الإجمالي يجمع آلاف القطع، فيتّسع للأرقام الكبيرة بالدقّة نفسها.
            $table->decimal('total_cbm', 14, 6)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cbm', 12, 4)->nullable()->change();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('cbm', 12, 4)->nullable()->change();
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->decimal('cbm_per_unit', 12, 4)->default(0)->change();
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->decimal('total_cbm', 12, 4)->default(0)->change();
        });
    }
};
