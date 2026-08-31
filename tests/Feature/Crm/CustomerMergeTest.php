<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * دمج العملاء المكرّرين.
 *
 * ## ما تحرسه هذه الاختبارات
 *
 * العميل ليس صفًّا في جدول: له حسابٌ فرعيّ في «ذمم العملاء» بقيودٍ مُرحَّلة.
 * فدمجٌ ينقل الطلبات ويترك الرصيد يُخفي السجلّ من القائمة ويُبقي دَينه على
 * الدفاتر — فلا يعود مجموعُ أرصدة العملاء يساوي حساب المراقبة، ويصير على
 * الشركة ذمّةٌ لا يطالب بها أحد لأن صاحبها لم يعد ظاهرًا.
 *
 * ولهذا يُقاس بعد كل دمج **مجموع «ذمم العملاء»**: هو الرقم الذي يكشف ضياع
 * الرصيد أو تضاعفه، ولا يكشفه رصيدُ الباقي وحده.
 */
class CustomerMergeTest extends TestCase
{
    use RefreshDatabase;

    private CustomerService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);

        $this->service = app(CustomerService::class);
    }

    private function customer(string $name, float $opening = 0, ?string $phone = null): Customer
    {
        return $this->service->create([
            'name' => $name,
            'primary_phone' => $phone,
            'opening_balance' => $opening,
        ]);
    }

    /** رصيد حسابٍ بالكود — بفروعه، كما يقرؤه ميزان المراجعة. */
    private function balance(string $code): float
    {
        return round(app(AccountingService::class)->accountBalance(
            Account::where('code', $code)->firstOrFail(),
        ), 2);
    }

    private function outstanding(Customer $customer): float
    {
        return Customer::withTrashed()->withOutstandingBalance()
            ->findOrFail($customer->id)->outstandingBalance();
    }

    // ────────── الرصيد ينتقل ولا يضيع ──────────

    /** **الرصيد ينتقل إلى الباقي ويصفر حساب المُدمَج.** */
    public function test_the_balance_moves_to_the_surviving_record(): void
    {
        $source = $this->customer('عمر قفيشه/جمله', 4080);
        $target = $this->customer('عمر قفيشة / جملة', 4550);

        $this->service->merge($source, $target);

        $this->assertSame(8630.0, $this->outstanding($target));
        $this->assertSame(0.0, $this->outstanding($source));
    }

    /**
     * **ومجموع «ذمم العملاء» لا يتغيّر.**
     *
     * الدمج نقلٌ بين حسابين تحت المراقبة نفسه، لا كسبٌ ولا خسارة. ورقمٌ يتحرّك
     * هنا يعني دَينًا اختُرع أو ضاع.
     */
    public function test_the_receivables_control_account_is_untouched(): void
    {
        $source = $this->customer('سائد شاهين/جمله', 363.95);
        $target = $this->customer('سائد شاهين / جملة', 200);

        $before = $this->balance('1100');
        $this->service->merge($source, $target);

        $this->assertSame($before, $this->balance('1100'));
        $this->assertSame(563.95, $this->outstanding($target));
    }

    /** **ورصيدٌ دائن ينتقل دائنًا** — الدفعة المقدَّمة له لا عليه. */
    public function test_a_credit_balance_stays_a_credit(): void
    {
        $source = $this->customer('محمود اللحسه', -400);
        $target = $this->customer('محمود اللحسة', 1000);

        $this->service->merge($source, $target);

        $this->assertSame(600.0, $this->outstanding($target));
        $this->assertSame(0.0, $this->outstanding($source));
    }

    /** ولا قيد لمن رصيده صفر — قيدٌ بلا أثرٍ يُثقل الكشف. */
    public function test_no_entry_is_posted_for_a_zero_balance(): void
    {
        $source = $this->customer('زبون أ');
        $target = $this->customer('زبون ب');

        $this->service->merge($source, $target);

        $this->assertDatabaseMissing('journal_entries', ['source' => 'customer_merge']);
    }

    /**
     * **والقيود القديمة تبقى كما هي.**
     *
     * كشفُ حسابٍ طُبع أمس يجب أن يُطبع اليوم كما هو (BR-ACC-09): الرصيد ينتقل
     * بقيدٍ جديد، لا بنقل سطور قيدٍ مُرحَّل من حسابٍ إلى حساب.
     */
    public function test_posted_lines_are_never_re_pointed(): void
    {
        $source = $this->customer('عدي جعيه', 900);
        $target = $this->customer('عدي جعية');

        $account = $source->glAccount()->first();
        $linesBefore = $account->lines()->count();

        $this->service->merge($source, $target);

        // سطرٌ واحد يُضاف (طرف قيد النقل) ولا يُنقل شيء من القديم.
        $this->assertSame($linesBefore + 1, $account->lines()->count());
        $this->assertDatabaseHas('journal_entries', [
            'source' => 'customer_merge',
            'reference_id' => $source->id,
            'status' => 'posted',
        ]);
    }

    // ────────── وما يتعلّق به ينتقل معه ──────────

    /** **سندات القبض تنتقل** — وبدونها يفقد الكشف الموحَّد ما دفعه العميل. */
    public function test_receipt_vouchers_follow_the_customer(): void
    {
        $source = $this->customer('نسيم جملة');
        $target = $this->customer('نسيم - جملة');

        $voucher = FinancialVoucher::create([
            'uuid' => (string) Str::uuid(),
            'number' => 'RC-TEST-1',
            'kind' => 'receipt',
            'status' => 'draft',
            'voucher_date' => now()->toDateString(),
            'treasury_id' => Treasury::firstOrFail()->id,
            'customer_id' => $source->id,
            'amount' => 250,
        ]);

        $this->service->merge($source, $target);

        $this->assertSame($target->id, $voucher->fresh()->customer_id);
    }

    /** والهواتف والعناوين تنتقل، ولا يبقى للمُدمَج «أساسيّ» ينازع الباقي. */
    public function test_phones_move_and_lose_their_primary_flag(): void
    {
        $source = $this->service->create(
            ['name' => 'يوسف زبون', 'primary_phone' => '0599111222'],
            [['phone' => '0599111222', 'is_primary' => true]],
        );
        $target = $this->customer('يوسف/زبون');

        $this->service->merge($source, $target);

        $this->assertSame(1, $target->phones()->count());
        $this->assertSame(0, $target->phones()->where('is_primary', true)->count());
    }

    /** ويُملأ ناقصُ الباقي مما عند المُدمَج، ولا يُكتب فوق قائم. */
    public function test_missing_fields_are_filled_but_never_overwritten(): void
    {
        $source = $this->customer('طه الكركي', 0, '0599777888');
        $target = $this->customer('طه الكركى');

        $this->service->merge($source, $target);
        $this->assertSame('0599777888', $target->fresh()->primary_phone);

        $third = $this->customer('طه الكركي.', 0, '0599000111');
        $this->service->merge($third, $target->fresh());
        $this->assertSame('0599777888', $target->fresh()->primary_phone);
    }

    /** والمُدمَج يُحذف ناعمًا ويُعلَّم، وحسابه يُعطَّل ولا يُحذف. */
    public function test_the_merged_record_is_marked_and_its_account_deactivated(): void
    {
        $source = $this->customer('عميل مكرّر', 100);
        $target = $this->customer('عميل مكرر');

        $account = $source->glAccount()->first();
        $this->service->merge($source, $target);

        $this->assertSoftDeleted('customers', ['id' => $source->id, 'merged_into_id' => $target->id]);
        $this->assertFalse((bool) $account->fresh()->is_active);
    }

    /** وسلسلة الدمج تُقصَّر: من دُمج في المُدمَج يُشير إلى الباقي مباشرةً. */
    public function test_an_earlier_merge_is_re_pointed_to_the_survivor(): void
    {
        $first = $this->customer('أحمد');
        $second = $this->customer('احمد');
        $third = $this->customer('أحمد.');

        $this->service->merge($first, $second);
        $this->service->merge($second->fresh(), $third);

        $this->assertSame($third->id, Customer::withTrashed()->find($first->id)->merged_into_id);
    }

    // ────────── الحرّاس ──────────

    public function test_a_customer_cannot_be_merged_into_itself(): void
    {
        $customer = $this->customer('عميل');

        $this->expectException(ValidationException::class);
        $this->service->merge($customer, $customer);
    }

    public function test_merging_into_an_already_merged_record_is_refused(): void
    {
        $a = $this->customer('أ');
        $b = $this->customer('ب');
        $c = $this->customer('ج');

        $this->service->merge($b, $c);

        $this->expectException(ValidationException::class);
        $this->service->merge($a, $b->fresh());
    }

    // ────────── الكشف ──────────

    /** **التطبيع يجمع صور الاسم الواحد**: همزة وتاء مربوطة وفاصلة. */
    public function test_arabic_name_normalization_groups_the_same_name(): void
    {
        $this->assertSame(
            $this->service->normalizeName('عمر قفيشه/جمله'),
            $this->service->normalizeName('عُمر قفيشة - جملة'),
        );

        $this->assertNotSame(
            $this->service->normalizeName('عمر شاهين'),
            $this->service->normalizeName('عمر قفيشه'),
        );
    }

    /** والمجموعات تُبنى بالهاتف وبالاسم، ولا يتكرّر عميلٌ في مجموعتين. */
    public function test_duplicate_groups_cover_phone_and_name_without_overlap(): void
    {
        $this->customer('سائد شاهين/جمله', 0, '0599123456');
        $this->customer('اسم آخر تمامًا', 0, '0599123456');
        $this->customer('عدي جعيه');
        $this->customer('عدي جعية');
        $this->customer('وحيدٌ لا شبيه له');

        $groups = $this->service->duplicateGroups();

        $this->assertSame(2, $groups->count());
        $this->assertEqualsCanonicalizing(['phone', 'name'], $groups->pluck('by')->all());

        $ids = $groups->flatMap(fn ($g) => $g['customers']->pluck('id'));
        $this->assertSame($ids->count(), $ids->unique()->count());
    }

    /** ولا مجموعة لمن لا هاتف له — الفراغ ليس تطابقًا. */
    public function test_blank_phones_are_not_a_match(): void
    {
        $this->customer('اسم أوّل');
        $this->customer('اسم ثانٍ');

        $this->assertTrue($this->service->duplicateGroups()->isEmpty());
    }

    // ────────── الشاشة والصلاحية ──────────

    public function test_the_duplicates_screen_lists_the_groups(): void
    {
        $this->customer('عمر قفيشه/جمله', 4080);
        $this->customer('عمر قفيشة / جملة', 4550);

        $this->get(route('admin.crm.customers.duplicates'))
            ->assertOk()
            ->assertSee('العملاء المكرّرون')
            ->assertSee('عمر قفيشه/جمله');
    }

    public function test_merging_from_the_screen_redirects_to_the_survivor(): void
    {
        $source = $this->customer('عمر قفيشه/جمله', 4080);
        $target = $this->customer('عمر قفيشة / جملة', 4550);

        $this->post(route('admin.crm.customers.merge', $source), ['target' => $target->uuid])
            ->assertRedirect(route('admin.crm.customers.show', $target))
            ->assertSessionHas('success');

        $this->assertSame(8630.0, $this->outstanding($target));
    }

    // ────────── ومنعُ التكرار من أوّله ──────────

    /** **الحفظ يتوقّف عند المتشابه** — ولا يُنشأ سجلٌّ ثانٍ بلا انتباه. */
    public function test_creating_a_look_alike_warns_before_saving(): void
    {
        $this->customer('عمر قفيشه/جمله', 4080);

        $this->from(route('admin.crm.customers.create'))
            ->post(route('admin.crm.customers.store'), [
                'name' => 'عمر قفيشة / جملة',
                'primary_phone' => '0599123456',
            ])
            ->assertRedirect(route('admin.crm.customers.create'))
            ->assertSessionHas('duplicate_matches');

        $this->assertSame(1, Customer::where('name', 'like', '%قفيش%')->count());
    }

    /** والتأكيد يمرّ — «زبون» اسمٌ لعشرة مختلفين، والمنعُ يُعطّل الإدخال. */
    public function test_confirming_creates_the_second_record(): void
    {
        $this->customer('زبون');

        $this->post(route('admin.crm.customers.store'), [
            'name' => 'زبون',
            'primary_phone' => '0599123456',
            'confirm_duplicate' => 1,
        ])->assertRedirect();

        $this->assertSame(2, Customer::where('name', 'زبون')->count());
    }

    /** **ومن لا يملك صلاحية الدمج لا يدمج.** */
    public function test_a_user_without_the_permission_cannot_merge(): void
    {
        $source = $this->customer('عميل أ', 500);
        $target = $this->customer('عميل ب');

        $viewer = User::factory()->create(['branch_id' => Branch::default()->id]);
        $viewer->givePermissionTo('crm.customers.view');

        $this->actingAs($viewer)
            ->post(route('admin.crm.customers.merge', $source), ['target' => $target->uuid])
            ->assertForbidden();

        $this->actingAs($viewer)->get(route('admin.crm.customers.duplicates'))->assertForbidden();

        $this->assertSame(500.0, $this->outstanding($source));
    }
}
