<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * حذف العميل (BR-CUST-13).
 *
 * الحذف ناعم — وهذا وجه خطره: يُخفي الاسم ويُبقي ما تحته. فمن له طلبات أو
 * حركات محاسبية يُحظر لا يُحذف، وإلا بقيت في الدفاتر حركاتٌ بلا صاحبٍ ظاهر.
 *
 * ويُستثنى **رصيدُه الافتتاحي وحده**: هو من صنع شاشة العميل نفسها، ويُعكس مع
 * الحذف فيعود حسابه صفرًا. ولولا ذلك لتعذّر حذف السجلّ المكرّر المُدخَل برصيدٍ
 * خطأً — وهو أكثر ما يُحذف من أجله عميل.
 */
class CustomerDeleteTest extends TestCase
{
    use RefreshDatabase;

    private CustomerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->service = app(CustomerService::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user;
    }

    private function customer(array $overrides = []): Customer
    {
        return $this->service->create(array_merge([
            'name' => 'عمر شاهين',
            'primary_phone' => '0599123456',
        ], $overrides));
    }

    private function balance(string $code): float
    {
        $lines = Account::where('code', $code)->firstOrFail()
            ->lines()->whereHas('entry', fn ($q) => $q->where('status', 'posted'))->get();

        return round($lines->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2);
    }

    // ────────── الحذف ──────────

    public function test_a_customer_without_history_is_soft_deleted(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())
            ->delete("/admin/crm/customers/{$customer->uuid}")
            ->assertRedirect(route('admin.crm.customers.index'));

        // ناعم: الصف يبقى للتدقيق، ويختفي من القوائم.
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->assertNull(Customer::find($customer->id));
    }

    /** وحسابه المحاسبي يُعطَّل ولا يُحذف — حذفُه يترك قيودًا بلا حساب. */
    public function test_deleting_deactivates_the_ledger_account(): void
    {
        $customer = $this->customer();
        $accountId = $customer->gl_account_id;

        $this->actingAs($this->admin())->delete("/admin/crm/customers/{$customer->uuid}");

        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'is_active' => false]);
    }

    /**
     * والرصيد الافتتاحي يُعكس مع الحذف فلا يبقى في الأصول.
     *
     * هذا جوهر الحراسة: بلا العكس كان الحذف يُخفي اسم العميل ويترك قيمته قائمةً
     * في ذمم العملاء — رقمٌ لا يُنسب إلى أحد ولا يُحصَّل أبدًا.
     */
    public function test_deleting_reverses_the_opening_balance(): void
    {
        $this->actingAs($this->admin());
        $customer = $this->customer(['opening_balance' => 660]);
        $code = $customer->glAccount->code;

        $this->assertEqualsWithDelta(660.0, $this->balance($code), 0.01);

        $this->service->delete($customer->fresh());

        $this->assertEqualsWithDelta(0.0, $this->balance($code), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->balance(config('accounting.opening.equity_account')), 0.01);
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    /** والقيد الأصلي يبقى معكوسًا لا محذوفًا (BR-ACC-09). */
    public function test_the_original_opening_entry_is_reversed_not_deleted(): void
    {
        $this->actingAs($this->admin());
        $customer = $this->customer(['opening_balance' => 660]);
        $entryId = $customer->opening_entry_id;

        $this->service->delete($customer->fresh());

        $this->assertTrue(JournalEntry::findOrFail($entryId)->isReversed());
    }

    /** وتصفيرُه يدويًا قبل الحذف لا يمنع الحذف — سطور العكس ليست تاريخًا غريبًا. */
    public function test_zeroing_the_balance_first_still_allows_deletion(): void
    {
        $this->actingAs($this->admin());
        $customer = $this->customer(['opening_balance' => 250]);

        $this->service->syncOpeningBalance($customer, 0);
        $this->service->delete($customer->fresh());

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    // ────────── الحراسة ──────────

    public function test_a_customer_with_orders_cannot_be_deleted(): void
    {
        $customer = $this->customer();
        Order::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($this->admin())
            ->from(route('admin.crm.customers.index'))
            ->delete("/admin/crm/customers/{$customer->uuid}")
            ->assertRedirect(route('admin.crm.customers.index'))
            ->assertSessionHasErrors('customer');

        $this->assertNotSoftDeleted('customers', ['id' => $customer->id]);
    }

    /**
     * وحركةٌ محاسبية غير رصيده الافتتاحي تمنع الحذف — ولو كان صافيها صفرًا.
     *
     * الصفر قد يخفي بيعًا ومرتجعًا، وكلاهما تاريخٌ لا يجوز أن يفقد صاحبه.
     */
    public function test_a_customer_with_other_ledger_entries_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin());
        $customer = $this->customer();

        app(AccountingService::class)->postEntry([
            'entry_date' => now()->toDateString(),
            'description' => 'قيد يدوي على حساب العميل',
            'source' => 'manual',
        ], [
            ['account_code' => $customer->glAccount->code, 'debit' => 100, 'credit' => 0],
            ['account_code' => config('accounting.opening.equity_account'), 'debit' => 0, 'credit' => 100],
        ]);

        $this->expectException(ValidationException::class);
        $this->service->delete($customer->fresh());
    }

    public function test_the_delete_guard_reports_the_reason_in_arabic(): void
    {
        $customer = $this->customer();
        Order::factory()->create(['customer_id' => $customer->id]);

        try {
            $this->service->delete($customer);
            $this->fail('A customer with orders should not be deletable.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('طلبات', $e->errors()['customer'][0]);
            $this->assertStringContainsString('حظره', $e->errors()['customer'][0]);
        }
    }

    // ────────── الصلاحية ──────────

    public function test_a_role_without_the_delete_permission_is_refused(): void
    {
        $customer = $this->customer();

        // البائع يملك view/create/update فقط (CrmPermissionSeeder).
        $this->actingAs($this->withRole('sales'))
            ->delete("/admin/crm/customers/{$customer->uuid}")
            ->assertForbidden();

        $this->assertNotSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_the_delete_button_is_hidden_from_a_role_that_cannot_delete(): void
    {
        $this->customer();

        $response = $this->actingAs($this->withRole('sales'))->get('/admin/crm/customers');

        $response->assertOk();
        $response->assertDontSee('_method');
    }

    public function test_the_delete_button_is_shown_to_an_admin(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())
            ->get('/admin/crm/customers')
            ->assertOk()
            ->assertSee(route('admin.crm.customers.destroy', $customer), false);
    }
}
