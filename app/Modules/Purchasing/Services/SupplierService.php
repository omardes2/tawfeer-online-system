<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * منطق أعمال الموردين: جهة اتصال أساسية واحدة كحدّ أقصى (§10)، وحساب فرعي محاسبي
 * لكل مورد تحت «ذمم الموردين» — تُرحَّل عليه قيود فواتير/مدفوعات المورد.
 */
class SupplierService
{
    public function create(array $data, array $contacts = []): Supplier
    {
        return DB::transaction(function () use ($data, $contacts) {
            // رمز تسلسلي تلقائي يبدأ من 1000 عند عدم تمريره (نموذج الإضافة لا يرسله).
            if (empty($data['code'])) {
                $data['code'] = $this->nextCode();
            }
            $opening = $this->extractOpening($data);

            $supplier = Supplier::create($data);
            $this->syncContacts($supplier, $contacts);
            $this->ensureLedgerAccount($supplier);

            if ($opening !== null) {
                $this->syncOpeningBalance($supplier, $opening);
            }

            // مزامنة القيم الافتراضية من قاعدة البيانات (مثل is_active) للاستجابة.
            $supplier->refresh();

            return $supplier;
        });
    }

    /**
     * الرمز التسلسلي التالي للمورد: أكبر رمز رقمي + 1، بدءًا من 1000.
     *
     * **يشمل المحذوفين ناعمًا** لأن قيد التفرّد في قاعدة البيانات يشملهم: بدون
     * `withTrashed()` يُعاد استخدام رمز موردٍ محذوف فيفشل الإدراج بـUniqueConstraintViolation.
     */
    public function nextCode(): string
    {
        $max = Supplier::withTrashed()->pluck('code')
            ->filter(fn ($c) => ctype_digit((string) $c))
            ->map(fn ($c) => (int) $c)
            ->max();

        $next = max(1000, ($max ?? 999) + 1);
        while (Supplier::withTrashed()->where('code', (string) $next)->exists()) {
            $next++;
        }

        return (string) $next;
    }

    public function update(Supplier $supplier, array $data, ?array $contacts = null): Supplier
    {
        $opening = $this->extractOpening($data);

        return DB::transaction(function () use ($supplier, $data, $contacts, $opening) {
            $supplier->update($data);
            $this->ensureLedgerAccount($supplier); // ينشئه إن غاب، ويزامن الاسم إن تغيّر

            if ($opening !== null) {
                $this->syncOpeningBalance($supplier, $opening);
            }

            if ($contacts !== null) {
                $supplier->contacts()->delete();
                $this->syncContacts($supplier, $contacts);
            }

            return $supplier;
        });
    }

    /**
     * ينتزع الرصيد الافتتاحي من بيانات النموذج.
     *
     * لا يُمرَّر إلى `Supplier::create/update` مع بقيّة الحقول — وهذا بالضبط ما
     * كان يحدث قبل هذه المرحلة: الرقم يُكتب على الصفّ ويُعرض في القائمة وصفحة
     * المورد، ولا قيد له في الدفاتر.
     *
     * وغيابُ المفتاح (`null`) يعني «لا تمسّه» لا «صفّره»: حفظٌ لا يحمل الحقل
     * (لغياب الصلاحية) كان سيعكس قيدًا مُرحّلًا بلا أن يطلب أحدٌ ذلك.
     *
     * @param  array<string, mixed>  $data
     */
    private function extractOpening(array &$data): ?float
    {
        if (! array_key_exists('opening_balance', $data)) {
            return null;
        }

        $value = $data['opening_balance'];
        unset($data['opening_balance']);

        return $value === null || $value === '' ? 0.0 : round((float) $value, 2);
    }

    /**
     * ترحيل الرصيد الافتتاحي للمورد — أو تصحيحه.
     *
     * الموجب يعني أننا **مدينون له**: دائن حسابه الفرعي في «ذمم الموردين» (خصم)
     * / مدين رأس المال. والسالب يعني دفعةً مقدَّمة منّا فينعكس الطرفان.
     *
     * وتغييرُ الرقم بعد ترحيله **يعكس القيد الأصلي ويُرحّل مصحَّحًا** — المُرحّل
     * لا يُعدَّل ولا يُحذف (BR-ACC-09).
     */
    public function syncOpeningBalance(Supplier $supplier, float $amount): Supplier
    {
        $amount = round($amount, 2);
        $unchanged = abs($amount - (float) $supplier->opening_balance) < 0.01;

        // «لم يتغيّر» لا يكفي وحده: رصيدٌ من قبل هذه المرحلة يحمل رقمًا بلا قيد،
        // وردُّه بلا عمل كان يُبقيه خارج الدفاتر إلى الأبد.
        if ($unchanged && ($supplier->opening_entry_id !== null || abs($amount) < 0.01)) {
            return $supplier;
        }

        $account = $supplier->glAccount()->first() ?: $this->ensureLedgerAccount($supplier);
        if (! $account) {
            throw ValidationException::withMessages([
                'opening_balance' => __('لا يمكن ترحيل رصيد افتتاحي قبل تهيئة دليل الحسابات.'),
            ]);
        }

        $accounting = app(AccountingService::class);
        $equity = config('accounting.opening.equity_account', '3010');

        return DB::transaction(function () use ($supplier, $account, $amount, $accounting, $equity) {
            $existing = $supplier->openingEntry()->first();
            if ($existing && ! $existing->isReversed()) {
                $accounting->reverse($existing, [
                    'description' => __('عكس رصيد افتتاحي للمورد :name', ['name' => $supplier->name]),
                ]);
            }

            $entry = null;
            if (abs($amount) >= 0.01) {
                $value = abs($amount);
                $lines = $amount > 0
                    ? [
                        ['account_code' => $equity, 'debit' => $value, 'credit' => 0],
                        ['account_code' => $account->code, 'debit' => 0, 'credit' => $value],
                    ]
                    : [
                        ['account_code' => $account->code, 'debit' => $value, 'credit' => 0],
                        ['account_code' => $equity, 'debit' => 0, 'credit' => $value],
                    ];

                $entry = $accounting->postEntry([
                    'entry_date' => now()->toDateString(),
                    'description' => __('رصيد افتتاحي للمورد :name', ['name' => $supplier->name]),
                    'source' => 'supplier_opening',
                    'reference_type' => 'supplier',
                    'reference_id' => $supplier->id,
                ], $lines);
            }

            $supplier->forceFill([
                'opening_balance' => $amount,
                'opening_entry_id' => $entry?->id,
            ])->save();

            return $supplier;
        });
    }

    public function delete(Supplier $supplier): void
    {
        $supplier->delete();
    }

    /**
     * يضمن وجود حساب فرعي للمورد تحت «ذمم الموردين» (الحساب الأب من الإعدادات).
     * idempotent: ينشئ الحساب مرّة واحدة ويربطه، ثم يزامن الاسم عند تغيّره.
     */
    public function ensureLedgerAccount(Supplier $supplier): ?Account
    {
        $parent = Account::where('code', config('accounting.purchasing.payable_account'))->first();
        if (! $parent) {
            return null; // دليل الحسابات غير مُهيّأ بعد — لا نُعطّل إنشاء المورد.
        }

        // موجود مسبقًا: نُزامن الاسم فقط إن تغيّر.
        if ($supplier->gl_account_id && ($account = $supplier->glAccount()->first())) {
            $name = $this->accountName($supplier);
            if ($account->name !== $name) {
                $account->update(['name' => $name]);
            }

            return $account;
        }

        $account = Account::create([
            'code' => $this->nextChildCode($parent),
            'name' => $this->accountName($supplier),
            'type' => $parent->type,               // خصم (liability) مثل الأب
            'parent_id' => $parent->id,
            'is_postable' => true,                 // الترحيل يكون على الفرعي
            'currency' => $parent->currency,
            'is_active' => true,
        ]);

        $supplier->forceFill(['gl_account_id' => $account->id])->save();

        return $account;
    }

    private function accountName(Supplier $supplier): string
    {
        return __('ذمم المورد: :name', ['name' => $supplier->name]);
    }

    /** كود فرعي فريد تحت الأب بنمط «2010-0001». */
    private function nextChildCode(Account $parent): string
    {
        $seq = (int) Account::where('parent_id', $parent->id)->count() + 1;

        do {
            $code = $parent->code.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $seq++;
        } while (Account::where('code', $code)->exists());

        return $code;
    }

    private function syncContacts(Supplier $supplier, array $contacts): void
    {
        $primaryTaken = false;

        foreach ($contacts as $contact) {
            $isPrimary = (bool) ($contact['is_primary'] ?? false);
            if ($isPrimary && $primaryTaken) {
                $isPrimary = false; // جهة أساسية واحدة كحدّ أقصى
            }
            $primaryTaken = $primaryTaken || $isPrimary;

            $supplier->contacts()->create([
                'name' => $contact['name'],
                'position' => $contact['position'] ?? null,
                'email' => $contact['email'] ?? null,
                'phone' => $contact['phone'] ?? null,
                'is_primary' => $isPrimary,
                'notes' => $contact['notes'] ?? null,
            ]);
        }
    }
}
