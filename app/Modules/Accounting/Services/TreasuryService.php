<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Sales\Models\Order;
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
                'currency' => $data['currency'] ?? 'ILS',
                // يبقى صفرًا هنا ويُكتب من `syncOpeningBalance` مع قيده معًا،
                // فلا يوجد لحظةٌ يحمل فيها العمودُ رقمًا لا قيد له.
                'opening_balance' => 0,
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $data['is_default'] ?? false,
                'bank_name' => $data['bank_name'] ?? null,
                'account_name' => $data['account_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'iban' => $data['iban'] ?? null,
                'swift' => $data['swift'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->syncOpeningBalance($treasury, round((float) ($data['opening_balance'] ?? 0), 2));

            return $treasury;
        });
    }

    /**
     * ترحيل الرصيد الافتتاحي للخزينة — أو تصحيحه بعد الإنشاء.
     *
     * كان يُرحَّل مرّة واحدة لحظة الإنشاء ثم يُغلق البابُ: من نسيه لم يجد له
     * مدخلًا إلا قيدًا يدويًا يضبط الدفاتر ويترك عمود «افتتاحي» صفرًا، فيقرأ
     * صاحبُه رقمين متناقضين عن خزينةٍ واحدة.
     *
     * والقيد: مدين حساب الخزينة (أصل) / دائن رأس المال. وتغييرُ الرقم بعد
     * ترحيله **يعكس الأصل ويُرحّل مصحَّحًا** — المُرحّل لا يُعدَّل (BR-ACC-09).
     */
    public function syncOpeningBalance(Treasury $treasury, float $amount): Treasury
    {
        $amount = round($amount, 2);

        if (abs($amount - (float) $treasury->opening_balance) < 0.01) {
            return $treasury; // لا تغيير — ولا قيد بلا أثر.
        }

        $account = $treasury->glAccount()->first();
        if (! $account) {
            throw ValidationException::withMessages([
                'opening_balance' => __('الخزينة بلا حساب محاسبي — لا يمكن ترحيل رصيد افتتاحي.'),
            ]);
        }

        $equity = config('accounting.opening.equity_account', config('accounting.treasury.opening_equity', '3010'));

        return DB::transaction(function () use ($treasury, $account, $amount, $equity) {
            $existing = $treasury->openingEntry()->first();
            if ($existing && ! $existing->isReversed()) {
                $this->accounting->reverse($existing, [
                    'description' => __('عكس رصيد افتتاحي — :name', ['name' => $treasury->name]),
                ]);
            }

            $entry = null;
            if (abs($amount) >= 0.01) {
                $value = abs($amount);
                // الشاشة لا تقبل سالبًا اليوم، لكن الإشارة تُحسم هنا لا هناك:
                // صحّة القيد لا تُترك لتحقّقٍ في نموذج قد يتغيّر.
                $lines = $amount > 0
                    ? [
                        ['account_code' => $account->code, 'debit' => $value, 'credit' => 0],
                        ['account_code' => $equity, 'debit' => 0, 'credit' => $value],
                    ]
                    : [
                        ['account_code' => $equity, 'debit' => $value, 'credit' => 0],
                        ['account_code' => $account->code, 'debit' => 0, 'credit' => $value],
                    ];

                $entry = $this->accounting->postEntry([
                    'entry_date' => now()->toDateString(),
                    'description' => __('رصيد افتتاحي — :name', ['name' => $treasury->name]),
                    'source' => 'system',
                    'reference_type' => 'treasury_opening',
                    'reference_id' => $treasury->id,
                ], $lines);
            }

            $treasury->forceFill([
                'opening_balance' => $amount,
                'opening_entry_id' => $entry?->id,
            ])->save();

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

        // غيابُ المفتاح يعني «لا تمسّه» لا «صفّره»: حفظٌ لا يحمل الحقل كان
        // سيعكس قيدًا مُرحّلًا بلا أن يطلب أحدٌ ذلك.
        if (array_key_exists('opening_balance', $data)) {
            $value = $data['opening_balance'];
            $this->syncOpeningBalance($treasury, $value === null || $value === '' ? 0.0 : (float) $value);
        }

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

        // حساب فرعي مخصّص (قابل للترحيل) تحت حساب المراقبة المناسب:
        // الخزائن النقدية تحت «حساب النقدية 1011»، والبنوك تحت «الحسابات البنكية 1020».
        $parentCode = ($data['type'] ?? 'cash') === 'bank'
            ? config('accounting.treasury.bank_account', '1020')
            : config('accounting.treasury.cash_account', '1011');
        $parent = Account::where('code', $parentCode)->first()
            ?? Account::where('code', config('accounting.treasury.assets_parent', '1000'))->first();

        return Account::create([
            // كود فرعي متسلسل تحت الأب (مثل 1011-0001) ليظهر مباشرةً تحته في الدليل.
            'code' => $this->nextChildCode($parent),
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'type' => 'asset',
            'parent_id' => $parent?->id,
            'is_postable' => true,
            'currency' => $data['currency'] ?? 'ILS',
            'is_active' => true,
        ]);
    }

    /**
     * اسمُ الطرف ورقمُ تتبّع الشحنة لكل قيد — المفتاح معرّف القيد.
     *
     * **رقم التتبّع** هو ما تُكتب به فاتورة شركة التوصيل، بينما يحمل السند **رقم
     * الطلب**. فبغيره تُطابَق مئات السطور بالمبلغ وحده — والمبالغ تتكرّر كثيرًا،
     * فتضيع المطابقة.
     *
     * و**اسم الزبون** يُقرأ ولا يُحفظ حرفًا: الطلب قد يُحرَّر ويُلغى ويُعاد، والاسم
     * على السند هو المرجع.
     *
     * الاثنان معًا في جلبةٍ واحدة على السندات: الشاشتان تحتاجانهما، وفصلُهما كان
     * يُكرّر الاستعلام على كشفٍ يعرض مئة حركة أو أكثر.
     *
     * @param  array<int, int>  $entryIds
     * @return array{parties: array<int, string>, trackings: array<int, string>}
     */
    public function entryMeta(array $entryIds): array
    {
        if ($entryIds === []) {
            return ['parties' => [], 'trackings' => []];
        }

        $vouchers = FinancialVoucher::whereIn('journal_entry_id', $entryIds)
            ->with(['supplier:id,name', 'customer:id,name', 'employee:id,name'])
            ->get(['id', 'journal_entry_id', 'supplier_id', 'customer_id', 'employee_id', 'party_name', 'reference']);

        $references = $vouchers->pluck('reference')->filter()->unique()->values()->all();

        $orders = $references === []
            ? collect()
            : Order::whereIn('number', $references)->get(['number', 'customer_name', 'tracking_number'])->keyBy('number');

        return [
            // اسمُ السند أولًا، ثم اسمُ الزبون على الطلب: سندُ التحصيل الآليّ لا
            // يحمل عميلًا مسجَّلًا حين يُطلَب الطلبُ باسمٍ وهاتفٍ بلا حساب.
            'parties' => $vouchers->mapWithKeys(fn (FinancialVoucher $v) => [
                $v->journal_entry_id => $v->supplier?->name
                    ?? $v->customer?->name
                    ?? $v->employee?->name
                    ?? $v->party_name
                    ?? $orders->get((string) $v->reference)?->customer_name,
            ])->filter()->all(),

            'trackings' => $vouchers->mapWithKeys(fn (FinancialVoucher $v) => [
                $v->journal_entry_id => $orders->get((string) $v->reference)?->tracking_number,
            ])->filter()->all(),
        ];
    }

    /**
     * كود فرعي فريد تحت الأب بنمط «1011-0001» — **شاملًا المحذوف ناعمًا**.
     *
     * قيد التفرّد على `accounts.code` لا يعرف الحذف الناعم: خزينةٌ حُذف حسابها
     * تُبقي رمزه محجوزًا. فالعدّ والفحص بمُستعلمٍ يُخفي المحذوف يُعيدان استعمال
     * رمزٍ مشغول فيفشل الإدراج.
     */
    private function nextChildCode(Account $parent): string
    {
        $seq = (int) Account::withTrashed()->where('parent_id', $parent->id)
            ->where('code', 'like', $parent->code.'-%')->count() + 1;

        do {
            $code = $parent->code.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $seq++;
        } while (Account::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    /** رمز مقترح للخزينة الجديدة. */
    public function nextCode(string $type): string
    {
        return NumberGenerator::next('treasuries', 'code', $type === 'bank' ? 'BNK' : 'CB', (int) now()->year, 3);
    }
}
