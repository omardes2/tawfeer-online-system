<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إدارة الخزائن والبنوك (Phase 7.1). كل خزينة مرتبطة بحساب GL مخصّص؛ **الرصيد يُشتقّ دائمًا
 * من القيود المُرحّلة** (لا رصيد مخزَّن). كل عملية تمرّ عبر AccountingService (قيد متوازن).
 */
class TreasuryService
{
    public function __construct(private readonly AccountingService $accounting) {}

    /**
     * إنشاء خزينة/بنك. يربط حساب GL موجودًا أو يُنشئ حسابًا فرعيًا مخصّصًا،
     * ويُرحّل قيد رصيد افتتاحي إن وُجد (مدين الخزينة / دائن حقوق الملكية).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Treasury
    {
        return DB::transaction(function () use ($data) {
            $account = $this->resolveGlAccount($data);

            $treasury = Treasury::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'name_en' => $data['name_en'] ?? null,
                'type' => $data['type'] ?? 'cash',
                'gl_account_id' => $account->id,
                'currency' => $data['currency'] ?? 'SAR',
                'opening_balance' => round((float) ($data['opening_balance'] ?? 0), 2),
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $data['is_default'] ?? false,
                'bank_name' => $data['bank_name'] ?? null,
                'account_name' => $data['account_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'iban' => $data['iban'] ?? null,
                'swift' => $data['swift'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $opening = round((float) ($treasury->opening_balance), 2);
            if ($opening > 0) {
                $equity = config('accounting.treasury.opening_equity', '3010');
                $this->accounting->postEntry([
                    'entry_date' => now()->toDateString(),
                    'description' => __('رصيد افتتاحي — :name', ['name' => $treasury->name]),
                    'source' => 'system',
                    'reference_type' => 'treasury_opening',
                    'reference_id' => $treasury->id,
                ], [
                    ['account_code' => $account->code, 'debit' => $opening, 'credit' => 0],
                    ['account_code' => $equity, 'debit' => 0, 'credit' => $opening],
                ]);
            }

            return $treasury;
        });
    }

    /**
     * تعديل بيانات الخزينة (لا يغيّر حساب GL بعد الإنشاء — حفاظًا على سلامة الرصيد).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Treasury $treasury, array $data): Treasury
    {
        $treasury->update(collect($data)->only([
            'name', 'name_en', 'currency', 'is_active', 'bank_name',
            'account_name', 'account_number', 'iban', 'swift',
        ])->all());

        return $treasury;
    }

    /** الرصيد الحالي = رصيد حساب GL من القيود المُرحّلة (يشمل الافتتاحي). */
    public function balance(Treasury $treasury): float
    {
        if (! $treasury->glAccount) {
            return 0.0;
        }

        return round($this->accounting->accountBalance($treasury->glAccount), 2);
    }

    /** حركة الخزينة: سطور القيود المُرحّلة على حساب GL (الأحدث أولًا). */
    public function movements(Treasury $treasury, int $limit = 100)
    {
        if (! $treasury->glAccount) {
            return collect();
        }

        return $treasury->glAccount->lines()
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted'))
            ->with('entry:id,number,entry_date,description,source')
            ->latest('id')->limit($limit)->get();
    }

    /** حذف آمن: يُمنع عند وجود سندات مرتبطة أو حركة مُرحّلة. */
    public function delete(Treasury $treasury): void
    {
        $used = FinancialVoucher::where('treasury_id', $treasury->id)
            ->orWhere('counter_treasury_id', $treasury->id)->exists();
        if ($used || abs($this->balance($treasury)) > 0.001) {
            throw ValidationException::withMessages([
                'treasury' => __('لا يمكن حذف خزينة عليها حركة أو سندات. عطّلها بدلًا من ذلك.'),
            ]);
        }

        $treasury->delete();
    }

    /** يربط حساب GL موجودًا (فريد) أو يُنشئ حسابًا فرعيًا مخصّصًا للخزينة. */
    private function resolveGlAccount(array $data): Account
    {
        if (! empty($data['gl_account_id'])) {
            $account = Account::find($data['gl_account_id']);
            if (! $account || ! $account->is_postable) {
                throw ValidationException::withMessages(['gl_account_id' => __('حساب GL غير صالح للترحيل.')]);
            }
            if (Treasury::where('gl_account_id', $account->id)->exists()) {
                throw ValidationException::withMessages(['gl_account_id' => __('حساب GL مرتبط بخزينة أخرى.')]);
            }

            return $account;
        }

        // حساب فرعي مخصّص (قابل للترحيل) تحت الحساب الرئيس المناسب:
        // الخزائن النقدية تحت «الصندوق 1010»، والبنوك تحت «البنك 1020» (وإلا مجموعة الأصول).
        $parentCode = ($data['type'] ?? 'cash') === 'bank'
            ? config('accounting.treasury.bank_account', '1020')
            : config('accounting.treasury.cash_account', '1010');
        $parent = Account::where('code', $parentCode)->first()
            ?? Account::where('code', config('accounting.treasury.assets_parent', '1000'))->first();
        $code = 'T-'.$data['code'];

        return Account::create([
            'code' => $code,
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'type' => 'asset',
            'parent_id' => $parent?->id,
            'is_postable' => true,
            'currency' => $data['currency'] ?? 'SAR',
            'is_active' => true,
        ]);
    }

    /** رمز مقترح للخزينة الجديدة. */
    public function nextCode(string $type): string
    {
        return NumberGenerator::next('treasuries', 'code', $type === 'bank' ? 'BNK' : 'CB', (int) now()->year, 3);
    }
}
