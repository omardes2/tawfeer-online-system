<?php

namespace Tests\Feature\Crm;

use App\Models\User;
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
 * الحذف ناعم، ولا يمرّ إلا لعميلٍ بلا أثر: من له طلبات أو حركات محاسبية مُرحّلة
 * أو رصيد يُحظر لا يُحذف — وإلا بقيت في الدفاتر حركاتٌ بلا صاحبٍ ظاهر، ورصيدٌ
 * مستحقٌّ لا يطالب به أحد.
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

    public function test_a_customer_with_an_opening_balance_cannot_be_deleted(): void
    {
        // رصيدٌ افتتاحي يُرحَّل قيدًا في «ذمم العملاء» — حذفه يترك القيد بلا صاحب.
        $this->actingAs($this->admin());
        $customer = $this->customer(['opening_balance' => 250]);

        $this->delete("/admin/crm/customers/{$customer->uuid}")
            ->assertSessionHasErrors('customer');

        $this->assertNotSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_zeroing_the_balance_does_not_make_a_customer_with_entries_deletable(): void
    {
        $this->actingAs($this->admin());
        $customer = $this->customer(['opening_balance' => 250]);

        $this->service->syncOpeningBalance($customer, 0);

        // تصفير الرصيد يعكس القيد ولا يمحوه (BR-ACC-09)، فالتاريخ المحاسبي باقٍ
        // والحذف يبقى ممنوعًا — الرصيد صفر لا يعني «بلا حركات».
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
