<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * إعادة ترميز حسابات الخزائن الفرعية بنمط الأب (مثل «1010-0001») بدل «T-...»،
 * لتظهر مباشرةً تحت حسابها الرئيس في دليل الحسابات (كما يظهر ذمم الموردين 2010-0001).
 *
 * الترميز يعتمد على معرّف الحساب (account_id) في الترحيل والخزائن، فتغيير الكود آمن
 * ولا يمسّ القيود. idempotent: يتخطّى الحسابات المُرمّزة سلفًا والحسابين الرئيسين.
 */
return new class extends Migration
{
    public function up(): void
    {
        $map = [
            'cash' => config('accounting.treasury.cash_account', '1010'),
            'bank' => config('accounting.treasury.bank_account', '1020'),
        ];

        foreach ($map as $type => $parentCode) {
            $parent = DB::table('accounts')->where('code', $parentCode)->first();
            if (! $parent) {
                continue;
            }

            $seq = (int) DB::table('accounts')->where('parent_id', $parent->id)
                ->where('code', 'like', $parentCode.'-%')->count();

            $treasuries = DB::table('treasuries')->where('type', $type)
                ->whereNotNull('gl_account_id')->orderBy('id')->get();

            foreach ($treasuries as $t) {
                $account = DB::table('accounts')->where('id', $t->gl_account_id)->first();
                if (! $account || $account->code === $parentCode) {
                    continue; // الحساب الرئيس نفسه — لا يُرمّز.
                }
                if (str_starts_with($account->code, $parentCode.'-')) {
                    continue; // مُرمّز سلفًا.
                }

                do {
                    $seq++;
                    $code = $parentCode.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
                } while (DB::table('accounts')->where('code', $code)->exists());

                DB::table('accounts')->where('id', $account->id)
                    ->update(['code' => $code, 'parent_id' => $parent->id, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // لا عكس آمن للترميز (لا نحفظ الكود القديم)؛ نتركه كما هو.
    }
};
