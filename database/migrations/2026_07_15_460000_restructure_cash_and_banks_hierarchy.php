<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * إعادة هيكلة «النقدية والبنوك» في الدليل المحاسبي (طلب المستخدم):
 *
 *   1010 «النقدية والبنوك»  (مراقبة — غير قابلة للترحيل)
 *     ├── 1011 «حساب النقدية»      (مراقبة) — تضم الخزائن النقدية
 *     │     ├── 1011-0001 «الصندوق الرئيسي»  (طرفي، قابل للترحيل)
 *     │     └── 1011-000N … بقية الخزائن النقدية
 *     └── 1020 «الحسابات البنكية»  (مراقبة) — تضم البنوك
 *           ├── 1020-000N «البنك الرئيسي» + بقية البنوك (طرفية، قابلة للترحيل)
 *
 * قبل التعديل كان 1010 «الصندوق» و1020 «البنك» حسابين طرفيين تُرحَّل عليهما الحركة
 * مباشرةً. تنقل الهجرة قيود الحركة (journal_lines) وارتباط الخزائن (treasuries.gl_account_id)
 * وخرائط الترحيل (account_mappings) إلى حسابات طرفية جديدة، ثم تحوّل 1010/1011/1020 إلى
 * حسابات مراقبة. idempotent — لا تُكرِّر إن نُفِّذت، ولا تعمل على قاعدة فارغة (البذور تبني
 * البنية النهائية مباشرةً).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cashBanks = DB::table('accounts')->where('code', '1010')->first();

        // قاعدة فارغة (البذور تبني البنية) أو مُنفَّذة سلفًا → لا شيء.
        if (! $cashBanks || ($cashBanks->name === 'النقدية والبنوك' && ! $cashBanks->is_postable)) {
            return;
        }

        DB::transaction(function () use ($cashBanks) {
            $now = now();

            // 1) حساب المراقبة «حساب النقدية 1011» تحت «النقدية والبنوك 1010».
            $cashGroupId = $this->firstOrCreateAccount('1011', [
                'name' => 'حساب النقدية', 'type' => 'asset',
                'parent_id' => $cashBanks->id, 'is_postable' => false,
            ], $now);

            // 2) الصندوق الرئيسي: حساب طرفي جديد (1011-0001) يرث كل حركة/ارتباط 1010 الحالي.
            $mainCashId = $this->firstOrCreateAccount('1011-0001', [
                'name' => 'الصندوق الرئيسي', 'type' => 'asset',
                'parent_id' => $cashGroupId, 'is_postable' => true, 'currency' => 'SAR', 'is_active' => true,
            ], $now);
            $this->relinkAll($cashBanks->id, $mainCashId, $now);

            // 3) نقل الخزائن النقدية الفرعية القائمة (1010-000N) تحت «حساب النقدية 1011»
            //    وإعادة ترميزها 1011-000N لتظهر متداخلةً بشكل صحيح.
            $this->reparentAndRecodeChildren($cashBanks->id, $cashGroupId, '1011', $now);

            // 4) تحويل 1010 إلى «النقدية والبنوك» (مراقبة). يبقى أبوه (مجموعة الأصول 1000).
            DB::table('accounts')->where('id', $cashBanks->id)->update([
                'name' => 'النقدية والبنوك', 'is_postable' => false, 'updated_at' => $now,
            ]);

            // 5) الحسابات البنكية: تحويل 1020 «البنك» إلى حساب مراقبة تحت 1010، مع نقل
            //    حركته/ارتباطه إلى «البنك الرئيسي» طرفي جديد. أبناؤه (1020-000N) كما هم.
            $bank = DB::table('accounts')->where('code', '1020')->first();
            if ($bank) {
                if ($bank->is_postable) {
                    $mainBankCode = $this->nextChildCode('1020');
                    $mainBankId = $this->firstOrCreateAccount($mainBankCode, [
                        'name' => 'البنك الرئيسي', 'type' => 'asset',
                        'parent_id' => $bank->id, 'is_postable' => true, 'currency' => 'SAR', 'is_active' => true,
                    ], $now);
                    $this->relinkAll($bank->id, $mainBankId, $now);
                }
                DB::table('accounts')->where('id', $bank->id)->update([
                    'name' => 'الحسابات البنكية', 'is_postable' => false,
                    'parent_id' => $cashBanks->id, 'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // لا عكس — إعادة الهيكلة نهائية (البيانات المُرحّلة لا تُفكّك آليًا).
    }

    /** إنشاء حساب إن لم يوجد وإرجاع معرّفه. */
    private function firstOrCreateAccount(string $code, array $attrs, $now): int
    {
        $existing = DB::table('accounts')->where('code', $code)->value('id');
        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('accounts')->insertGetId(array_merge($attrs, [
            'uuid' => (string) Str::uuid(),
            'code' => $code,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    /** نقل كل ارتباطات حساب قديم (قيود/خزائن/خرائط ترحيل) إلى حساب طرفي جديد. */
    private function relinkAll(int $fromId, int $toId, $now): void
    {
        DB::table('journal_lines')->where('account_id', $fromId)->update(['account_id' => $toId]);
        DB::table('treasuries')->where('gl_account_id', $fromId)->update(['gl_account_id' => $toId, 'updated_at' => $now]);
        if (DB::getSchemaBuilder()->hasTable('account_mappings')) {
            DB::table('account_mappings')->where('account_id', $fromId)->update(['account_id' => $toId, 'updated_at' => $now]);
        }
    }

    /** نقل أبناء الحساب الطرفيين (code-000N) إلى أب جديد وإعادة ترميزهم بنمطه. */
    private function reparentAndRecodeChildren(int $oldParentId, int $newParentId, string $newPrefix, $now): void
    {
        $children = DB::table('accounts')
            ->where('parent_id', $oldParentId)
            ->where('code', 'like', '1010-%')
            ->orderBy('code')->get();

        foreach ($children as $child) {
            DB::table('accounts')->where('id', $child->id)->update([
                'code' => $this->nextChildCode($newPrefix),
                'parent_id' => $newParentId,
                'updated_at' => $now,
            ]);
        }
    }

    /** أوّل كود فرعي متاح بنمط «PREFIX-000N». */
    private function nextChildCode(string $prefix): string
    {
        $seq = DB::table('accounts')->where('code', 'like', $prefix.'-%')->count() + 1;
        do {
            $code = $prefix.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $seq++;
        } while (DB::table('accounts')->where('code', $code)->exists());

        return $code;
    }
};
