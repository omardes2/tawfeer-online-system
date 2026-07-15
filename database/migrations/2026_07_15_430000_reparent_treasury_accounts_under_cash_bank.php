<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * نقل حسابات الخزائن الفرعية المُنشأة تلقائيًا لتصبح تحت الحساب الرئيس المناسب:
 * الخزائن النقدية تحت «الصندوق 1010»، والبنوك تحت «البنك 1020» — بدل مجموعة الأصول 1000.
 *
 * لا يمسّ الحسابين الرئيسين 1010/1020 نفسيهما (الخزينة/البنك الافتراضيان مرتبطان بهما
 * مباشرة). idempotent: تشغيله ثانيةً لا يغيّر شيئًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cashCode = config('accounting.treasury.cash_account', '1010');
        $bankCode = config('accounting.treasury.bank_account', '1020');
        $cashId = DB::table('accounts')->where('code', $cashCode)->value('id');
        $bankId = DB::table('accounts')->where('code', $bankCode)->value('id');

        if ($cashId) {
            DB::table('accounts')->where('code', '!=', $cashCode)
                ->whereIn('id', fn ($q) => $q->select('gl_account_id')->from('treasuries')
                    ->where('type', 'cash')->whereNotNull('gl_account_id'))
                ->update(['parent_id' => $cashId, 'updated_at' => now()]);
        }

        if ($bankId) {
            DB::table('accounts')->where('code', '!=', $bankCode)
                ->whereIn('id', fn ($q) => $q->select('gl_account_id')->from('treasuries')
                    ->where('type', 'bank')->whereNotNull('gl_account_id'))
                ->update(['parent_id' => $bankId, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // عكس: إعادة الحسابات الفرعية للخزائن إلى مجموعة الأصول 1000.
        $assetsId = DB::table('accounts')->where('code', config('accounting.treasury.assets_parent', '1000'))->value('id');
        if ($assetsId) {
            $cashCode = config('accounting.treasury.cash_account', '1010');
            $bankCode = config('accounting.treasury.bank_account', '1020');
            DB::table('accounts')
                ->whereNotIn('code', [$cashCode, $bankCode])
                ->whereIn('id', fn ($q) => $q->select('gl_account_id')->from('treasuries')->whereNotNull('gl_account_id'))
                ->update(['parent_id' => $assetsId, 'updated_at' => now()]);
        }
    }
};
