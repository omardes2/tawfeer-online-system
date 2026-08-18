<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الرصيد الافتتاحي للعميل.
 *
 * ما على العميل قبل دخوله النظام ليس رقمًا يُعرض، بل **قيدٌ في الدفاتر**: مدين
 * حسابه الفرعي في «ذمم العملاء» (أصل) / دائن رأس المال. وهذه الاختبارات تحرس
 * الأمرين معًا — أن يظهر في الأصول، وألّا يتضاعف مع كل حفظ.
 */
class CustomerOpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private CustomerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->service = app(CustomerService::class);
    }

    private function customer(float $opening = 0): Customer
    {
        return $this->service->create([
            'name' => 'عمر شاهين',
            'primary_phone' => '0599123456',
            'opening_balance' => $opening,
        ]);
    }

    /** رصيد حسابٍ من القيود المُرحّلة (مدين − دائن). */
    private function balance(string $code): float
    {
        $account = Account::where('code', $code)->firstOrFail();

        $lines = $account->lines()->whereHas('entry', fn ($q) => $q->where('status', 'posted'))->get();

        return round($lines->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2);
    }

    private function equityCode(): string
    {
        return config('accounting.opening.equity_account');
    }

    // ────────── القيد ──────────

    /** الرصيد الافتتاحي يزيد الأصول ويُقابله رأس المال. */
    public function test_an_opening_balance_debits_the_customer_and_credits_capital(): void
    {
        $customer = $this->customer(1500);

        $this->assertNotNull($customer->opening_entry_id);
        $this->assertEqualsWithDelta(1500.0, $this->balance($customer->glAccount->code), 0.01);
        $this->assertEqualsWithDelta(-1500.0, $this->balance($this->equityCode()), 0.01); // دائن
        $this->assertSame('posted', $customer->openingEntry->status);
    }

    /** ويظهر في رصيد العميل المشتقّ من دفاتره لا من عمودٍ مخزَّن. */
    public function test_the_opening_balance_shows_in_the_customer_ledger(): void
    {
        $customer = $this->customer(800);

        $lines = $customer->glAccount->lines()->get();

        $this->assertCount(1, $lines);
        $this->assertEqualsWithDelta(800.0, (float) $lines->first()->debit, 0.01);
    }

    /**
     * والسالب ينعكس طرفاه — دفعةٌ مقدَّمة من العميل.
     *
     * منعُ السالب كان سيُجبر المستخدم على تجاهل الدفعات المقدَّمة أو إدخالها
     * بقيدٍ يدوي خارج الشاشة.
     */
    public function test_a_negative_opening_balance_credits_the_customer(): void
    {
        $customer = $this->customer(-400);

        $this->assertEqualsWithDelta(-400.0, $this->balance($customer->glAccount->code), 0.01);
        $this->assertEqualsWithDelta(400.0, $this->balance($this->equityCode()), 0.01); // مدين
    }

    /** والصفر لا يُنشئ قيدًا بلا أثر. */
    public function test_a_zero_opening_balance_posts_nothing(): void
    {
        $customer = $this->customer(0);

        $this->assertNull($customer->opening_entry_id);
        $this->assertSame(0, JournalEntry::where('source', 'customer_opening')->count());
    }

    // ────────── الحارس ──────────

    /**
     * حفظٌ ثانٍ بالقيمة نفسها لا يُرحّل مرّة أخرى.
     *
     * بلا هذا الحارس كان كل فتحٍ لصفحة التعديل وحفظ — لتصحيح هاتفٍ مثلًا —
     * يُضيف الرصيد من جديد فيتضاعف بصمت.
     */
    public function test_saving_again_does_not_post_a_second_entry(): void
    {
        $customer = $this->customer(1500);

        $this->service->update($customer->fresh(), ['name' => 'عمر شاهين', 'opening_balance' => 1500]);

        $this->assertSame(1, JournalEntry::where('source', 'customer_opening')->count());
        $this->assertEqualsWithDelta(1500.0, $this->balance($customer->glAccount->code), 0.01);
    }

    /** وحفظٌ بلا الحقل أصلًا لا يمحو رصيدًا مُرحّلًا. */
    public function test_saving_without_the_field_keeps_the_balance(): void
    {
        $customer = $this->customer(1500);

        $this->service->update($customer->fresh(), ['name' => 'عمر ش.']);

        $this->assertEqualsWithDelta(1500.0, (float) $customer->fresh()->opening_balance, 0.01);
        $this->assertEqualsWithDelta(1500.0, $this->balance($customer->glAccount->code), 0.01);
    }

    /** وتغيير الرقم يعكس الأصل ويُرحّل مصحَّحًا — لا يُعدَّل قيد مُرحّل. */
    public function test_changing_the_amount_reverses_and_reposts(): void
    {
        $customer = $this->customer(1500);
        $firstEntryId = $customer->opening_entry_id;

        $this->service->update($customer->fresh(), ['opening_balance' => 900]);
        $updated = $customer->fresh();

        $this->assertNotSame($firstEntryId, $updated->opening_entry_id);
        $this->assertTrue(JournalEntry::find($firstEntryId)->isReversed());
        // الأثر الصافي = الرقم الجديد وحده.
        $this->assertEqualsWithDelta(900.0, $this->balance($updated->glAccount->code), 0.01);
        $this->assertEqualsWithDelta(-900.0, $this->balance($this->equityCode()), 0.01);
    }

    /** وتصفيره يعكس القيد ولا يترك قيدًا جديدًا. */
    public function test_clearing_the_amount_reverses_without_a_new_entry(): void
    {
        $customer = $this->customer(1500);

        $this->service->update($customer->fresh(), ['opening_balance' => 0]);
        $updated = $customer->fresh();

        $this->assertNull($updated->opening_entry_id);
        $this->assertEqualsWithDelta(0.0, $this->balance($updated->glAccount->code), 0.01);
    }

    // ────────── الصلاحية ──────────

    /**
     * من لا يملك صلاحية القيود لا يُحرّك الدفاتر من شاشة العميل.
     *
     * موظّف المبيعات يُنشئ العملاء يوميًّا؛ وإدخالُ رصيدٍ افتتاحي قيدٌ في
     * اليومية لا بيانُ عميل.
     */
    public function test_a_user_without_journal_permission_cannot_set_it(): void
    {
        $sales = User::factory()->create(['branch_id' => Branch::default()->id]);
        $sales->assignRole('sales');

        $this->actingAs($sales)->post(route('admin.crm.customers.store'), [
            'name' => 'زبون',
            'primary_phone' => '0599000111',
            'opening_balance' => 5000,
        ])->assertRedirect();

        $customer = Customer::where('name', 'زبون')->firstOrFail();

        $this->assertEqualsWithDelta(0.0, (float) $customer->opening_balance, 0.01);
        $this->assertSame(0, JournalEntry::where('source', 'customer_opening')->count());
    }

    /** ومن يملكها يُدخله من الشاشة فيُرحَّل. */
    public function test_an_authorized_user_posts_it_from_the_form(): void
    {
        $this->post(route('admin.crm.customers.store'), [
            'name' => 'زبون قديم',
            'primary_phone' => '0599000222',
            'opening_balance' => 2500,
        ])->assertRedirect();

        $customer = Customer::where('name', 'زبون قديم')->firstOrFail();

        $this->assertNotNull($customer->opening_entry_id);
        $this->assertEqualsWithDelta(2500.0, $this->balance($customer->glAccount->code), 0.01);
    }
}
