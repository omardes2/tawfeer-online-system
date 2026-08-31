<?php

namespace App\Modules\Crm\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
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
     * دمج عميلين مكرّرين (BR-CUST-14): كل ما يخصّ المُدمَج ينتقل إلى الباقي،
     * ورصيدُه ينتقل بقيدٍ لا بتعديل، ثم يُعلَّم المُدمَج ويُحذف ناعمًا.
     *
     * ## لماذا لا يكفي نقل الطلبات
     *
     * العميل ليس صفًّا في جدول: له حسابٌ فرعيّ في «ذمم العملاء» بقيودٍ مُرحَّلة.
     * ونقلُ الطلبات وحدها ثم حذفُ المُدمَج ناعمًا كان يُخفيه من القائمة ويُبقي
     * رصيده على حسابه — فلا يعود مجموعُ أرصدة العملاء يساوي حساب المراقبة،
     * ويصير على الدفاتر دَينٌ حقيقيّ لا يطالب به أحد لأن صاحبه لم يعد ظاهرًا.
     *
     * ## ولماذا الرصيد ينتقل بقيدٍ لا بنقل سطوره
     *
     * سطور القيد المُرحَّل لا تُنقل من حسابٍ إلى حساب: كشفُ حسابٍ طُبع أمس يجب
     * أن يُطبع اليوم كما هو (BR-ACC-09). فالدمج يُرحّل **قيد إعادة تصنيف**
     * بقيمة رصيد المُدمَج: مدين حساب الباقي / دائن حساب المُدمَج. فيصير حساب
     * المُدمَج صفرًا، ويحمل الباقي الذمّة كاملة، ولا يتغيّر مجموع «ذمم
     * العملاء» بشيء — لأن الطرفين كليهما تحته.
     *
     * والاتجاه ينعكس إن كان للمُدمَج رصيدٌ دائن (دفعة مقدَّمة): المال له لا
     * عليه، ونقلُه مدينًا كان سيقلب دفعةً مقبوضة إلى دَينٍ عليه.
     */
    public function merge(Customer $source, Customer $target): Customer
    {
        $this->assertMergeable($source, $target);

        return DB::transaction(function () use ($source, $target) {
            $this->moveLedgerBalance($source, $target);
            $this->moveRelations($source, $target);
            $this->fillGapsFrom($source, $target);

            // سلسلةُ الدمج تُقصَّر: من دُمج في المُدمَج سابقًا يُشير إلى الباقي
            // مباشرةً، وإلّا صار تتبّعُ عميلٍ قديم قفزًا بين سجلّاتٍ محذوفة.
            Customer::withTrashed()->where('merged_into_id', $source->id)
                ->update(['merged_into_id' => $target->id]);

            // الحساب يُعطَّل ولا يُحذف: حذفُه يترك قيودَه بلا حساب.
            $source->glAccount()->first()?->update(['is_active' => false]);

            $source->update(['merged_into_id' => $target->id]);
            $source->delete();

            return $target->fresh();
        });
    }

    /** @throws ValidationException */
    private function assertMergeable(Customer $source, Customer $target): void
    {
        if ($source->id === $target->id) {
            throw ValidationException::withMessages(['merge' => __('لا يمكن دمج العميل مع نفسه.')]);
        }

        if ($source->merged_into_id || $source->trashed()) {
            throw ValidationException::withMessages(['merge' => __('هذا العميل مدموجٌ أصلًا في سجلٍّ آخر.')]);
        }

        if ($target->merged_into_id || $target->trashed()) {
            throw ValidationException::withMessages(['merge' => __('لا يمكن الدمج في سجلٍّ محذوف أو مدموج — اختر السجلّ الباقي.')]);
        }
    }

    /**
     * قيد إعادة تصنيف ينقل رصيد المُدمَج إلى الباقي فيصفّر حسابه.
     *
     * ولا قيد إن كان الرصيد صفرًا: قيدٌ بلا أثرٍ يُثقل الدفاتر ويُربك الكشف.
     */
    private function moveLedgerBalance(Customer $source, Customer $target): void
    {
        $from = $source->glAccount()->first();
        if (! $from) {
            return; // لا حساب — ولا رصيد ينتقل.
        }

        $accounting = app(AccountingService::class);
        $balance = round($accounting->accountBalance($from), 2);

        if (abs($balance) < 0.01) {
            return;
        }

        $to = $target->glAccount()->first() ?: $this->ensureLedgerAccount($target);
        if (! $to) {
            throw ValidationException::withMessages([
                'merge' => __('لا يمكن نقل الرصيد قبل تهيئة حساب العميل الباقي.'),
            ]);
        }

        $value = abs($balance);
        $lines = $balance > 0
            ? [ // على المُدمَج دَين: يُنقل إلى ذمّة الباقي.
                ['account_code' => $to->code, 'debit' => $value, 'credit' => 0],
                ['account_code' => $from->code, 'debit' => 0, 'credit' => $value],
            ]
            : [ // للمُدمَج رصيدٌ دائن (دفعة مقدَّمة): يُنقل دائنًا كما هو.
                ['account_code' => $from->code, 'debit' => $value, 'credit' => 0],
                ['account_code' => $to->code, 'debit' => 0, 'credit' => $value],
            ];

        $accounting->postEntry([
            'entry_date' => now()->toDateString(),
            'description' => __('نقل رصيد بدمج العميل :from في :to', [
                'from' => $source->name,
                'to' => $target->name,
            ]),
            'source' => 'customer_merge',
            'reference_type' => 'customer',
            'reference_id' => $source->id,
        ], $lines);
    }

    /**
     * نقل كل ما يشير إلى المُدمَج.
     *
     * والمفضّلة والمراجعات لهما فريدٌ مركّب مع المنتج: صفٌّ مكرّر عند الباقي
     * يُحذف قبل النقل، وإلّا رفضته قاعدة البيانات فسقط الدمج كلّه.
     */
    private function moveRelations(Customer $source, Customer $target): void
    {
        $source->phones()->update(['customer_id' => $target->id, 'is_primary' => false]);
        $source->addresses()->update(['customer_id' => $target->id, 'is_default' => false]);
        $source->contacts()->update(['customer_id' => $target->id, 'is_primary' => false]);
        $source->customerNotes()->update(['customer_id' => $target->id]);

        Order::where('customer_id', $source->id)->update(['customer_id' => $target->id]);

        // سندات القبض: بدونها يفقد الكشفُ الموحَّد ما دفعه العميل فعلًا.
        FinancialVoucher::where('customer_id', $source->id)->update(['customer_id' => $target->id]);

        foreach (['carts', 'campaign_messages', 'message_suppressions', 'marketing_contacts', 'channel_contacts'] as $table) {
            DB::table($table)->where('customer_id', $source->id)->update(['customer_id' => $target->id]);
        }

        foreach (['wishlist_items', 'product_reviews'] as $table) {
            $taken = DB::table($table)->where('customer_id', $target->id)->pluck('product_id');
            DB::table($table)->where('customer_id', $source->id)->whereIn('product_id', $taken)->delete();
            DB::table($table)->where('customer_id', $source->id)->update(['customer_id' => $target->id]);
        }
    }

    /**
     * يملأ فراغات الباقي مما عند المُدمَج — ولا يكتب فوق قيمةٍ قائمة.
     *
     * فالمكرّر كثيرًا ما يحمل الهاتف أو البريد الذي نقص الأوّل، وضياعُه في
     * الدمج يعني فقدَ وسيلة الوصول إلى العميل. والكتابةُ فوق القائم عكسُها:
     * تُبدّل بياناتٍ صحيحة ببياناتٍ أدخِلت على عجل.
     */
    private function fillGapsFrom(Customer $source, Customer $target): void
    {
        $fill = [];
        foreach (['primary_phone', 'email', 'user_id', 'category', 'birth_date'] as $field) {
            if (blank($target->{$field}) && filled($source->{$field})) {
                $fill[$field] = $source->{$field};
            }
        }

        if ($fill !== []) {
            $target->forceFill($fill)->save();
            $this->ensureLedgerAccount($target); // الاسم لم يتغيّر، لكن الحساب قد يغيب.
        }
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

    /**
     * تطبيع الاسم العربي للمقارنة — لا للعرض.
     *
     * الاسم الواحد يُكتب صورًا: «عمر قفيشه/جمله» و«عمر قفيشة / جملة» و«عمر
     * قفيشه-جمله». والمقارنة الحرفية تراها ثلاثة عملاء، فيتفرّق دَينُ رجلٍ
     * واحد على ثلاثة سجلّات لا يجمعها كشف.
     *
     * فتُوحَّد صور الهمزة والتاء المربوطة والألف المقصورة، ويُحذف التشكيل
     * والتطويل، ثم كل ما ليس حرفًا ولا رقمًا — فالفاصلة والمسافة والشرطة
     * اختيارُ كاتبٍ لا فرقٌ في المُسمّى.
     */
    public function normalizeName(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }

        $name = preg_replace('/[\x{0640}\x{064B}-\x{0652}\x{0670}]/u', '', $name) ?? $name;
        $name = strtr($name, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
        ]);
        $name = preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? $name;

        return mb_strtolower($name);
    }

    /**
     * مرشَّحو الدمج مع عميلٍ بعينه — لقائمة الدمج في صفحته.
     *
     * الاستعلام مبدوءٌ بأوّل كلمةٍ من الاسم ومحدودٌ بخمسين: تطبيعُ الاسم لا
     * يُكتب في SQL، وجلبُ كل العملاء لتطبيعهم في PHP يُثقل كل فتحةٍ للصفحة.
     * فتُضيَّق الدائرة بالاستعلام ثم تُصفَّى بالتطبيع.
     *
     * @return Collection<int, Customer>
     */
    public function mergeCandidatesFor(Customer $customer): Collection
    {
        return $this->lookAlikes($customer->name, $customer->primary_phone, $customer->id);
    }

    /**
     * من يشبه هذا الاسم أو يحمل هذا الرقم — قبل الإنشاء وقبل الدمج.
     *
     * @return Collection<int, Customer>
     */
    public function lookAlikes(?string $name, ?string $phone, ?int $excludeId = null): Collection
    {
        $token = collect(preg_split('/\s+/u', trim((string) $name)))->first() ?: '';
        $phone = $this->normalizePhone((string) $phone);
        $normalized = $this->normalizeName($name);

        if ($token === '' && $phone === '') {
            return collect();
        }

        return Customer::query()
            ->whereNull('merged_into_id')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where(function ($q) use ($token, $phone) {
                $q->when($token !== '', fn ($w) => $w->where('name', 'like', '%'.$token.'%'));
                $q->when($phone !== '', fn ($w) => $w->orWhere('primary_phone', $phone));
            })
            ->withOutstandingBalance()
            ->limit(50)
            ->get()
            ->filter(fn ($c) => ($phone !== '' && $this->normalizePhone((string) $c->primary_phone) === $phone)
                || ($normalized !== '' && $this->normalizeName($c->name) === $normalized))
            ->values();
    }

    /**
     * مجموعات العملاء المرشَّحة للدمج — بالهاتف أوّلًا ثم بالاسم.
     *
     * **الهاتف يقين والاسم ظنّ**: رقمان متطابقان رجلٌ واحد، واسمان متطابقان قد
     * يكونان رجلين. ولهذا تُعرض المجموعات ولا تُدمج: «زبون» تتكرّر عشرًا وهم
     * عشرة، ودمجُهم آليًّا يخلط ذممًا لا تُفكّ بعدها.
     *
     * ومن دُمج أو حُذف خارج الحساب: الدمج يقع على الأحياء فقط.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function duplicateGroups(): Collection
    {
        $customers = Customer::query()
            ->whereNull('merged_into_id')
            ->withCount('orders')
            ->withOutstandingBalance()
            ->orderBy('id')
            ->get();

        $groups = collect();

        foreach (['phone', 'name'] as $by) {
            $seen = $groups->flatMap(fn ($g) => $g['customers']->pluck('id'))->flip();

            $groups = $groups->merge(
                $customers
                    // من ظهر في مجموعةٍ بالهاتف لا يُعاد بالاسم: مجموعتان لنفس
                    // العملاء تعنيان دمجًا مرّتين، والثانية على سجلٍّ محذوف.
                    ->reject(fn ($c) => $seen->has($c->id))
                    ->groupBy(fn ($c) => $by === 'phone'
                        ? $this->normalizePhone((string) $c->primary_phone)
                        : $this->normalizeName($c->name))
                    ->reject(fn ($group, $key) => $key === '' || $group->count() < 2)
                    ->map(fn ($group, $key) => [
                        'key' => $by.':'.$key,
                        'by' => $by,
                        'label' => $by === 'phone' ? $key : $group->first()->name,
                        'customers' => $group->values(),
                        'balance' => round($group->sum(fn ($c) => $c->outstandingBalance()), 2),
                        'orders' => (int) $group->sum('orders_count'),
                    ])
                    ->values()
            );
        }

        // الأثقل أوّلًا: مجموعةٌ عليها رصيدٌ أو طلبات هي التي تُفسد الكشف
        // والتقارير، ومجموعةُ أسماءٍ فارغة تنتظر.
        return $groups->sortByDesc(fn ($g) => [abs($g['balance']), $g['orders']])->values();
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
