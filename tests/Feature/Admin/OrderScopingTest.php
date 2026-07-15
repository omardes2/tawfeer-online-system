<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * موظف المبيعات/المسوّق (view_own) يرى ويصل فقط لطلباته هو، ولا يرى «مبيعات مباشرة».
 * أصحاب العرض الكامل (admin) يرون الجميع ويصلون للمبيعات المباشرة.
 */
class OrderScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    private function order(array $attrs = []): Order
    {
        return Order::factory()->create(array_merge(['channel' => 'manual'], $attrs));
    }

    public function test_sales_and_affiliate_have_view_own_not_full_view(): void
    {
        foreach (['sales', 'affiliate'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            $this->assertTrue($role->hasPermissionTo('sales.orders.view_own'), "$roleName يفتقد view_own");
            $this->assertFalse($role->hasPermissionTo('sales.orders.view'), "$roleName ما زال يملك العرض الكامل");
            $this->assertTrue($role->hasPermissionTo('sales.orders.create'), "$roleName يفتقد create");
        }
    }

    public function test_sales_user_sees_only_own_orders_in_list(): void
    {
        $sales = $this->user('sales');
        $this->order(['created_by' => $sales->id, 'customer_name' => 'زبون خاص بي']);
        $this->order(['created_by' => $this->admin()->id, 'customer_name' => 'زبون شخص آخر']);

        $res = $this->actingAs($sales)->get(route('admin.sales.orders.index'));
        $res->assertOk();
        $res->assertSee('زبون خاص بي');
        $res->assertDontSee('زبون شخص آخر');
    }

    public function test_sales_user_cannot_open_others_order(): void
    {
        $sales = $this->user('sales');
        $own = $this->order(['created_by' => $sales->id]);
        $other = $this->order(['created_by' => $this->admin()->id]);

        $this->actingAs($sales)->get(route('admin.sales.orders.show', $own))->assertOk();
        $this->actingAs($sales)->get(route('admin.sales.orders.show', $other))->assertForbidden();
    }

    public function test_admin_sees_all_orders(): void
    {
        $sales = $this->user('sales');
        $this->order(['created_by' => $sales->id, 'customer_name' => 'زبون واحد']);
        $this->order(['created_by' => $this->admin()->id, 'customer_name' => 'زبون اثنان']);

        $res = $this->actingAs($this->admin())->get(route('admin.sales.orders.index'));
        $res->assertOk();
        $res->assertSee('زبون واحد');
        $res->assertSee('زبون اثنان');
    }

    public function test_direct_sale_hidden_and_blocked_for_sales_and_affiliate(): void
    {
        foreach (['sales', 'affiliate'] as $role) {
            $user = $this->user($role);
            // بند «مبيعات مباشرة» غير ظاهر، والوصول المباشر ممنوع.
            $this->actingAs($user)->get(route('admin.sales.orders.direct.create'))->assertForbidden();
            // لكن إنشاء أوردر عادي متاح.
            $this->actingAs($user)->get(route('admin.sales.orders.create'))->assertOk();
        }

        // أصحاب العرض الكامل (admin) يصلون للمبيعات المباشرة.
        $this->actingAs($this->admin())->get(route('admin.sales.orders.direct.create'))->assertOk();
    }
}
