<?php

namespace App\Modules\Crm\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\PostingAccountResolver;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Models\CustomerAddress;
use App\Modules\Sales\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * منطق أعمال العملاء (ADR-030، BR-CUST). كل المنطق هنا لا في المتحكمات.
 */
class CustomerService
{
    public function create(array $data, array $phones = [], array $addresses = [], array $contacts = []): Customer
    {
        // الهاتف اختياري لعملاء الدخول الاجتماعي (يُكمَّل لاحقًا) — يُطبَّع إن وُجد فقط.
        $data['primary_phone'] = filled($data['primary_phone'] ?? null)
            ? $this->normalizePhone($data['primary_phone'])
            : null;

        $opening = $this->extractOpening($data);

        return DB::transaction(function () use ($data, $phones, $addresses, $contacts, $opening) {
            $customer = Customer::create($data + ['created_by' => auth()->id()]);
            $this->syncPhones($customer, $phones);
            $this->syncAddresses($customer, $addresses);
            $this->syncContacts($customer, $contacts);
            $this->ensureLedgerAccount($customer);

            if ($opening !== null) {
                $this->syncOpeningBalance($customer, $opening);
            }

            return $customer;
        });
    }

    public function update(Customer $customer, array $data, ?array $phones = null, ?array $addresses = null, ?array $contacts = null): Customer
    {
        if (isset($data['primary_phone'])) {
            $data['primary_phone'] = $this->normalizePhone($data['primary_phone']);
        }

        $opening = $this->extractOpening($data);

        return DB::transaction(function () use ($customer, $data, $phones, $addresses, $contacts, $opening) {
            $customer->update($data);
            $this->ensureLedgerAccount($customer); // ينشئه إن غاب، ويزامن الاسم إن تغيّر

            if ($opening !== null) {
                $this->syncOpeningBalance($customer, $opening);
            }

            if ($phones !== null) {
                $customer->phones()->delete();
                $this->syncPhones($customer, $phones);
            }
            if ($addresses !== null) {
                $customer->addresses()->delete();
                $this->syncAddresses($customer, $addresses);
            }
            if ($contacts !== null) {
                $customer->contacts()->delete();
                $this->syncContacts($customer, $contacts);
            }

            return $customer;
        });
    }

    /**
     * حذف ناعم فقط لكيان مهم (BR-CUST-13) — ولمن لا تاريخ له.
     *
     * العميل ليس صفًّا معزولًا: له طلبات، وحسابٌ فرعي في «ذمم العملاء» بقيودٍ
     * مُرحّلة، ورصيدٌ يظهر في كشف حسابه. وحذفه مع بقاء تلك القيود كان يترك
     * حركاتٍ في الدفاتر بلا صاحبٍ ظاهر، ورصيدًا مستحقًّا لا يطالب به أحد.
     *
     * فمن له تاريخ يُحظر (BR-CUST-12) لا يُحذف: الحظر يمنع الطلبات الجديدة
     * ويُبقي الدفاتر متّسقة.
     *
     * ويُستثنى من «التاريخ» **رصيدُ العميل الافتتاحي وحده**: هو من صنع شاشة
     * العميل نفسها، ويُعكس هنا في المعاملة نفسها فيعود الحساب صفرًا. ولولا هذا
     * الاستثناء لتعذّر حذف السجلّ المكرّر الذي أُدخل برصيدٍ خطأً — وهو أكثر ما
     * يُحذف من أجله عميل، ولما نفعه تصفيرُ الرصيد يدويًا لأن القيد يُعكس ولا
     * يُمحى فتبقى سطوره على الحساب.
     */
    public function delete(Customer $customer): void
    {
        $this->assertDeletable($customer);

        $account = $customer->glAccount()->first();

        DB::transaction(function () use ($customer, $account) {
            if ($customer->opening_entry_id) {
                $this->syncOpeningBalance($customer, 0); // عكسٌ لا محو (BR-ACC-09).
            }

            // الحساب يُعطَّل ولا يُحذف: حذفُه يترك قيودًا بلا حساب.
            $account?->update(['is_active' => false]);

            $customer->delete();
        });
    }

    /**
     * يرفض الحذف إن كان للعميل أثرٌ لا يصحّ فقدان صاحبه.
     *
     * @throws ValidationException
     */
    private function assertDeletable(Customer $customer): void
    {
        if ($customer->orders()->exists()) {
            throw ValidationException::withMessages([
                'customer' => __('لا يمكن حذف عميل له طلبات. يمكنك حظره بدلًا من ذلك.'),
            ]);
        }

        $account = $customer->glAccount()->first();

        if ($account && $this->hasForeignEntries($customer, $account)) {
            throw ValidationException::withMessages([
                'customer' => __('لا يمكن حذف عميل له حركات محاسبية مُرحّلة. يمكنك حظره بدلًا من ذلك.'),
            ]);
        }
    }

    /**
     * هل على حساب العميل قيودٌ غير رصيده الافتتاحي (وعكسِه)؟
     *
     * تُقاس بالمعرّفات لا بالرصيد: رصيدٌ صافيه صفر قد يخفي بيعًا ومرتجعًا،
     * وكلاهما تاريخٌ لا يجوز أن يفقد اسم صاحبه في ميزان المراجعة.
     */
    private function hasForeignEntries(Customer $customer, Account $account): bool
    {
        $openingIds = JournalEntry::query()
            ->where('source', 'customer_opening')
            ->where('reference_type', 'customer')
            ->where('reference_id', $customer->id)
            ->pluck('id');

        $exempt = $openingIds
            ->merge(JournalEntry::whereIn('reverses_entry_id', $openingIds)->pluck('id'))
            ->all();

        return $account->lines()->whereNotIn('journal_entry_id', $exempt)->exists();
    }

    /**
     * ينتزع الرصيد الافتتاحي من بيانات النموذج.
     *
     * لا يُمرَّر إلى `Customer::create/update` مع بقيّة الحقول: كتابةُ الرقم
     * إسنادًا جماعيًا كانت تترك رصيدًا معروضًا بلا قيدٍ يقابله في الدفاتر.
     * وغيابُ المفتاح (`null`) يعني «لا تمسّه» — لا «صفّره»؛ ففرقُ الأمرين أن
     * حفظًا لا يحمل الحقل (لغياب الصلاحية) كان سيمحو رصيدًا مُرحّلًا.
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
     * ترحيل الرصيد الافتتاحي للعميل — أو تصحيحه.
     *
     * الموجب يعني أن العميل **مدين لنا**: مدين حسابه الفرعي في «ذمم العملاء»
     * (أصل) / دائن رأس المال. والسالب يعني دفعةً مقدَّمة منه فينعكس الطرفان —
     * ومنعُ السالب كان سيُجبر المستخدم على تجاهل الدفعات المقدَّمة أو إدخالها
     * بقيدٍ يدوي خارج الشاشة.
     *
     * وتغييرُ الرقم بعد ترحيله **يعكس القيد الأصلي ويُرحّل مصحَّحًا** — القيد
     * المُرحّل لا يُعدَّل ولا يُحذف (BR-ACC-09).
     */
    public function syncOpeningBalance(Customer $customer, float $amount): Customer
    {
        $amount = round($amount, 2);

        if (abs($amount - (float) $customer->opening_balance) < 0.01) {
            return $customer; // لا تغيير — ولا قيد بلا أثر.
        }

        $account = $customer->glAccount()->first() ?: $this->ensureLedgerAccount($customer);
        if (! $account) {
            throw ValidationException::withMessages([
                'opening_balance' => __('لا يمكن ترحيل رصيد افتتاحي قبل تهيئة دليل الحسابات.'),
            ]);
        }

        $accounting = app(AccountingService::class);
        $equity = config('accounting.opening.equity_account');

        return DB::transaction(function () use ($customer, $account, $amount, $accounting, $equity) {
            $existing = $customer->openingEntry()->first();
            if ($existing && ! $existing->isReversed()) {
                $accounting->reverse($existing, [
                    'description' => __('عكس رصيد افتتاحي للعميل :name', ['name' => $customer->name]),
                ]);
            }

            $entry = null;
            if (abs($amount) >= 0.01) {
                $value = abs($amount);
                $lines = $amount > 0
                    ? [
                        ['account_code' => $account->code, 'debit' => $value, 'credit' => 0],
                        ['account_code' => $equity, 'debit' => 0, 'credit' => $value],
                    ]
                    : [
                        ['account_code' => $equity, 'debit' => $value, 'credit' => 0],
                        ['account_code' => $account->code, 'debit' => 0, 'credit' => $value],
                    ];

                $entry = $accounting->postEntry([
                    'entry_date' => now()->toDateString(),
                    'description' => __('رصيد افتتاحي للعميل :name', ['name' => $customer->name]),
                    'source' => 'customer_opening',
                    'reference_type' => 'customer',
                    'reference_id' => $customer->id,
                ], $lines);
            }

            $customer->forceFill([
                'opening_balance' => $amount,
                'opening_entry_id' => $entry?->id,
            ])->save();

            return $customer;
        });
    }

    /**
     * يضمن وجود حساب فرعي للعميل تحت «ذمم العملاء» (الحساب المُعدّ للترحيل).
     * idempotent: ينشئ الحساب مرّة واحدة ويربطه، ثم يزامن الاسم عند تغيّره.
     */
    public function ensureLedgerAccount(Customer $customer): ?Account
    {
        // الحساب الأب = حساب «ذمم العملاء» من إعدادات الترحيل (وإلا 1100 افتراضيًا).
        $parentCode = app(PostingAccountResolver::class)->code('receivable', null, 'sales_invoice') ?? '1100';
        $parent = Account::where('code', $parentCode)->first();
        if (! $parent) {
            return null; // دليل الحسابات غير مُهيّأ بعد — لا نُعطّل إنشاء العميل.
        }

        if ($customer->gl_account_id && ($account = $customer->glAccount()->first())) {
            $name = $this->accountName($customer);
            if ($account->name !== $name) {
                $account->update(['name' => $name]);
            }

            return $account;
        }

        $account = Account::create([
            'code' => $this->nextChildCode($parent),
            'name' => $this->accountName($customer),
            'type' => $parent->type,               // أصل (asset) مثل الأب
            'parent_id' => $parent->id,
            'is_postable' => true,                 // الترحيل يكون على الفرعي
            'currency' => $parent->currency,
            'is_active' => true,
        ]);

        $customer->forceFill(['gl_account_id' => $account->id])->save();

        return $account;
    }

    private function accountName(Customer $customer): string
    {
        return __('ذمم العميل: :name', ['name' => $customer->name]);
    }

    /** كود فرعي فريد تحت الأب بنمط «1100-0001». */
    private function nextChildCode(Account $parent): string
    {
        $seq = (int) Account::where('parent_id', $parent->id)->count() + 1;

        do {
            $code = $parent->code.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
            $seq++;
        } while (Account::where('code', $code)->exists());

        return $code;
    }

    /*
    | إدارة عناوين العميل المستقلّة (Phase 3.4 — تجربة العميل). تُعيد استخدام قاعدة
    | «افتراضي واحد» (BR-CUST-06). كل المنطق هنا، لا تكرار في متحكّمات الواجهة.
    */

    /** @param  array<string, mixed>  $data */
    public function addAddress(Customer $customer, array $data): void
    {
        DB::transaction(function () use ($customer, $data) {
            $isDefault = (bool) ($data['is_default'] ?? false) || $customer->addresses()->count() === 0;
            if ($isDefault) {
                $customer->addresses()->update(['is_default' => false]);
            }
            $customer->addresses()->create($this->addressAttributes($data, $isDefault));
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateAddress(Customer $customer, CustomerAddress $address, array $data): void
    {
        abort_unless($address->customer_id === $customer->id, 403);

        DB::transaction(function () use ($customer, $address, $data) {
            $isDefault = (bool) ($data['is_default'] ?? false);
            if ($isDefault) {
                $customer->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
            }
            $address->update($this->addressAttributes($data, $isDefault || $address->is_default));
        });
    }

    public function removeAddress(Customer $customer, CustomerAddress $address): void
    {
        abort_unless($address->customer_id === $customer->id, 403);
        $wasDefault = $address->is_default;
        $address->delete();

        // ترقية أوّل عنوان متبقٍّ ليكون الافتراضي إن حُذف الافتراضي.
        if ($wasDefault) {
            $next = $customer->addresses()->orderBy('id')->first();
            $next?->update(['is_default' => true]);
        }
    }

    public function setDefaultAddress(Customer $customer, CustomerAddress $address): void
    {
        abort_unless($address->customer_id === $customer->id, 403);
        DB::transaction(function () use ($customer, $address) {
            $customer->addresses()->update(['is_default' => false]);
            $address->update(['is_default' => true]);
        });
    }

    /** تحديث تفضيلات العميل (لغة/فرع مفضّل/تفضيلات تواصل — جاهزية تسويق، بلا منطق نمو). */
    public function updatePreferences(Customer $customer, array $data): Customer
    {
        $customer->update(array_intersect_key($data, array_flip([
            'preferred_locale', 'preferred_branch_id', 'communication_preferences',
        ])));

        return $customer->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function addressAttributes(array $data, bool $isDefault): array
    {
        return [
            'label' => $data['label'] ?? null,
            'recipient_name' => $data['recipient_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'governorate_id' => $data['governorate_id'] ?? null,
            'city_id' => $data['city_id'] ?? null,
            'area_id' => $data['area_id'] ?? null,
            'address_line' => $data['address_line'] ?? null,
            'is_default' => $isDefault,
        ];
    }

    public function addNote(Customer $customer, string $body): Customer
    {
        $customer->customerNotes()->create([
            'body' => $body,
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);

        return $customer;
    }

    /** حظر العميل (BR-CUST-12) — يمنع إنشاء طلبات جديدة. */
    public function block(Customer $customer, string $reason): Customer
    {
        $customer->update(['is_blocked' => true, 'blocked_reason' => $reason, 'blocked_at' => now()]);

        return $customer;
    }

    public function unblock(Customer $customer): Customer
    {
        $customer->update(['is_blocked' => false, 'blocked_reason' => null, 'blocked_at' => null]);

        return $customer;
    }

    /**
     * دمج عميلين مكرّرين (BR-CUST-14): ينقل الهواتف/العناوين/جهات الاتصال/الملاحظات/الطلبات
     * إلى السجل الباقي، ويعلّم المُدمَج ويحذفه ناعمًا. لا يُفقد أي طلب.
     */
    public function merge(Customer $source, Customer $target): Customer
    {
        if ($source->id === $target->id) {
            throw ValidationException::withMessages(['merge' => __('لا يمكن دمج العميل مع نفسه.')]);
        }

        return DB::transaction(function () use ($source, $target) {
            $source->phones()->update(['customer_id' => $target->id, 'is_primary' => false]);
            $source->addresses()->update(['customer_id' => $target->id, 'is_default' => false]);
            $source->contacts()->update(['customer_id' => $target->id, 'is_primary' => false]);
            $source->customerNotes()->update(['customer_id' => $target->id]);
            Order::where('customer_id', $source->id)->update(['customer_id' => $target->id]);

            $source->update(['merged_into_id' => $target->id]);
            $source->delete();

            return $target->fresh();
        });
    }

    /**
     * كشف التكرار بالهاتف (BR-CUST-03/05): يفحص primary_phone وكل أرقام العملاء.
     */
    public function findDuplicatesByPhone(string $phone, ?int $excludeId = null): Collection
    {
        $normalized = $this->normalizePhone($phone);

        return Customer::query()
            ->where(fn ($q) => $q->where('primary_phone', $normalized)
                ->orWhereHas('phones', fn ($p) => $p->where('phone', $normalized)))
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get();
    }

    /** تطبيع رقم الهاتف: أرقام فقط (إزالة +/فواصل/مسافات) لمطابقة تكرار متّسقة (BR-CUST-03). */
    public function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', trim($phone)) ?? $phone;
    }

    private function syncPhones(Customer $customer, array $phones): void
    {
        $primaryTaken = false;
        foreach ($phones as $p) {
            $isPrimary = (bool) ($p['is_primary'] ?? false);
            if ($isPrimary && $primaryTaken) {
                $isPrimary = false;
            }
            $primaryTaken = $primaryTaken || $isPrimary;

            $customer->phones()->create([
                'phone' => $this->normalizePhone($p['phone']),
                'label' => $p['label'] ?? null,
                'is_primary' => $isPrimary,
            ]);
        }
    }

    private function syncAddresses(Customer $customer, array $addresses): void
    {
        $defaultTaken = false;
        foreach ($addresses as $a) {
            $isDefault = (bool) ($a['is_default'] ?? false);
            if ($isDefault && $defaultTaken) {
                $isDefault = false;
            }
            $defaultTaken = $defaultTaken || $isDefault;

            $customer->addresses()->create([
                'label' => $a['label'] ?? null,
                'recipient_name' => $a['recipient_name'] ?? null,
                'phone' => isset($a['phone']) ? $this->normalizePhone($a['phone']) : null,
                'governorate_id' => $a['governorate_id'] ?? null,
                'city_id' => $a['city_id'] ?? null,
                'area_id' => $a['area_id'] ?? null,
                'address_line' => $a['address_line'] ?? null,
                'is_default' => $isDefault,
            ]);
        }
    }

    private function syncContacts(Customer $customer, array $contacts): void
    {
        $primaryTaken = false;
        foreach ($contacts as $c) {
            $isPrimary = (bool) ($c['is_primary'] ?? false);
            if ($isPrimary && $primaryTaken) {
                $isPrimary = false;
            }
            $primaryTaken = $primaryTaken || $isPrimary;

            $customer->contacts()->create([
                'name' => $c['name'],
                'position' => $c['position'] ?? null,
                'email' => $c['email'] ?? null,
                'phone' => isset($c['phone']) ? $this->normalizePhone($c['phone']) : null,
                'is_primary' => $isPrimary,
                'notes' => $c['notes'] ?? null,
            ]);
        }
    }
}
