<?php

use App\Modules\Foundation\Services\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * توحيد عملة النظام على الشيكل (ILS ‏₪) بدل الريال (SAR ‏ر.س) — طلب المستخدم.
 * يضبط الإعداد العام (الرمز/الرمز الثلاثي) ويحدّث سجلّات العملة القائمة (خزائن/سندات/
 * فواتير/حسابات). idempotent — إعادة التشغيل لا تغيّر شيئًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        // الإعداد العام (يُبطِل الكاش تلقائيًا).
        Settings::set('store.currency', 'ILS', 'store');
        Settings::set('store.currency_symbol', '₪', 'store');

        // سجلّات العملة القائمة: SAR → ILS (رمز العملة الثلاثي فقط، لا يمسّ المبالغ).
        foreach (['treasuries', 'financial_vouchers', 'purchase_invoices', 'accounts'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'currency')) {
                DB::table($table)->where('currency', 'SAR')->update(['currency' => 'ILS']);
            }
        }
    }

    public function down(): void
    {
        Settings::set('store.currency', 'SAR', 'store');
        Settings::set('store.currency_symbol', 'ر.س', 'store');

        foreach (['treasuries', 'financial_vouchers', 'purchase_invoices', 'accounts'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'currency')) {
                DB::table($table)->where('currency', 'ILS')->update(['currency' => 'SAR']);
            }
        }
    }
};
