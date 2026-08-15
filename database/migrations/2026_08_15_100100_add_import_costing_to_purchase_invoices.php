<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فواتير الاستيراد — المرحلة ١: العملات وأسعار الصرف والتكلفة الشاملة.
 *
 * الفاتورة تُدخَل بعملة المورد (رمبي/دولار) بينما النظام يحاسب بالشيكل، وتُحمَّل على
 * كل صنف مصاريفُه (عمولة مشتريات + شحن بحري حسب حجمه بالمتر المكعّب). فينشأ سعران
 * لكل بند: **السعر الحقيقي** (ما يُذمّ للمورد) و**التكلفة الشاملة** (قيمة المخزون).
 *
 * هذه المرحلة إدخال وحساب وعرض فقط — الترحيل المحاسبي لا يتغيّر: يبقى بالسعر
 * الحقيقي كما هو اليوم. الترحيل المزدوج (المخزون بالتكلفة الشاملة والفرق في حساب
 * «مصاريف استيراد مستحقة») يأتي في المرحلة ٢.
 *
 * الفاتورة المحلية غير متأثّرة: تُترك أسعار الصرف فارغة فتبقى الحسابات كما كانت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            // كم وحدةً من عملة الفاتورة تساوي دولارًا واحدًا (الرمبي ≈ 7.15، والدولار 1).
            $table->decimal('fx_rate_to_usd', 15, 6)->nullable()->after('currency');
            // كم من العملة الأساسية (شيكل) يساوي دولارًا واحدًا.
            $table->decimal('usd_rate', 15, 6)->nullable()->after('fx_rate_to_usd');
            // عمولة المشتريات — نسبة متغيّرة لكل فاتورة.
            $table->decimal('commission_rate', 6, 3)->default(0)->after('usd_rate');
            // تكلفة المتر المكعّب بالدولار — متغيّرة لكل شحنة.
            $table->decimal('cbm_rate_usd', 15, 4)->default(0)->after('commission_rate');
            // إجماليات مشتقّة تُحفظ للعرض والتقارير (تُعاد كتابتها في كل حفظ).
            $table->decimal('foreign_subtotal', 15, 2)->default(0)->after('cbm_rate_usd');
            $table->decimal('landed_subtotal', 15, 2)->default(0)->after('foreign_subtotal');
            $table->decimal('total_cbm', 12, 4)->default(0)->after('landed_subtotal');
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->decimal('unit_price_foreign', 15, 4)->default(0)->after('qty');
            $table->decimal('cbm_per_unit', 12, 4)->default(0)->after('unit_price_foreign');
            $table->decimal('landed_unit_cost', 15, 4)->default(0)->after('unit_cost');
            $table->decimal('landed_line_total', 15, 2)->default(0)->after('landed_unit_cost');
            // تعديل يدوي لعمود التكلفة: الآلة الحاسبة لا تدهسه بعد ذلك.
            $table->boolean('landed_is_manual')->default(false)->after('landed_line_total');
        });

        // سعر الوحدة بعد التحويل كسرٌ طويل (45 ¥ ⇒ 22.9720 ₪)؛ منزلتان تُضيّعان
        // فروقًا تتراكم على مئات القطع. المبالغ الإجمالية تبقى بمنزلتين.
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 2)->default(0)->change();
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->dropColumn([
                'unit_price_foreign', 'cbm_per_unit',
                'landed_unit_cost', 'landed_line_total', 'landed_is_manual',
            ]);
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'fx_rate_to_usd', 'usd_rate', 'commission_rate', 'cbm_rate_usd',
                'foreign_subtotal', 'landed_subtotal', 'total_cbm',
            ]);
        });
    }
};
