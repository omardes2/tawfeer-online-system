<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * نطاق رؤية الطلبات.
 *
 * المسوّق وموظف المبيعات لا يريان إلا طلباتهما — **بحكم الدور** لا بغياب صلاحية.
 * فمنحُ «العرض الكامل» سهوًا (تعديل دور، زارع، منح على مستوى المستخدم) كان يفتح
 * للمسوّق طلبات زملائه وأسماء عملائهم وأرقام هواتفهم بصمت.
 */
class OrderVisibilityScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $affiliate;

    private User $seller;

    private Order $affiliateOrder;

    private Order $sellerOrder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->affiliate = $this->userWithRole('affiliate');
        $this->seller = $this->userWithRole('sales');

        $this->affiliateOrder = Order::factory()->create([
            'created_by' => $this->affiliate->id,
            'customer_name' => 'زبون المسوّق',
        ]);
        $this->sellerOrder = Order::factory()->create([
            'created_by' => $this->seller->id,
            'customer_name' => 'زبونة موظفة المبيعات',
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_a_marketer_sees_only_their_own_orders(): void
    {
        $this->actingAs($this->affiliate)
            ->get(route('admin.sales.orders.index'))
            ->assertOk()
            ->assertSee('زبون المسوّق', false)
            ->assertDontSee('زبونة موظفة المبيعات', false);
    }

    public function test_a_seller_sees_only_their_own_orders(): void
    {
        $this->actingAs($this->seller)
            ->get(route('admin.sales.orders.index'))
            ->assertOk()
            ->assertSee('زبونة موظفة المبيعات', false)
            ->assertDontSee('زبون المسوّق', false);
    }

    public function test_a_stray_full_view_permission_does_not_open_other_orders(): void
    {
        // جوهر الإصلاح: الصلاحية مُنحت للمسوّق (كما حدث على الإنتاج) — والقيد يصمد.
        $this->affiliate->givePermissionTo('sales.orders.view');

        $this->actingAs($this->affiliate->fresh())
            ->get(route('admin.sales.orders.index'))
            ->assertOk()
            ->assertSee('زبون المسوّق', false)
            ->assertDontSee('زبونة موظفة المبيعات', false);
    }

    public function test_a_stray_permission_on_the_role_does_not_open_other_orders(): void
    {
        // ولو مُنحت للدور كلّه لا للمستخدم وحده.
        Role::findByName('affiliate')->givePermissionTo('sales.orders.view');

        $this->actingAs($this->affiliate->fresh())
            ->get(route('admin.sales.orders.index'))
            ->assertOk()
            ->assertDontSee('زبونة موظفة المبيعات', false);
    }

    public function test_a_custom_role_holding_view_own_is_restricted(): void
    {
        // دورٌ مخصَّص أُنشئ من صفحة الأدوار باسم غير معروف للكود: «عرض الخاص»
        // وحده يكفي لتقييده، فلا يعتمد الأمان على قائمة أسماء أدوار.
        $role = Role::findOrCreate('marketer_v2', 'web');
        $role->givePermissionTo('sales.orders.view_own', 'sales.orders.view');

        $custom = User::factory()->create(['branch_id' => Branch::default()->id]);
        $custom->assignRole($role);
        Order::factory()->create(['created_by' => $custom->id, 'customer_name' => 'زبون الدور المخصَّص']);

        $this->actingAs($custom->fresh())
            ->get(route('admin.sales.orders.index'))
            ->assertOk()
            ->assertSee('زبون الدور المخصَّص', false)
            ->assertDontSee('زبونة موظفة المبيعات', false);
    }

    public function test_a_marketer_cannot_open_another_persons_order_by_url(): void
    {
        // القائمة وحدها ليست حارسًا: الرابط المباشر مسارٌ ثانٍ.
        $this->affiliate->givePermissionTo('sales.orders.view');

        $this->actingAs($this->affiliate->fresh())
            ->get(route('admin.sales.orders.show', $this->sellerOrder))
            ->assertForbidden();
    }

    public function test_a_marketer_can_open_their_own_order(): void
    {
        $this->actingAs($this->affiliate)
            ->get(route('admin.sales.orders.show', $this->affiliateOrder))
            ->assertOk();
    }

    public function test_an_order_attributed_to_the_marketer_is_visible(): void
    {
        // طلبٌ أنشأه غيره لكنه مسوّقه — عمولتُه له فيراه.
        $referred = Order::factory()->create([
            'created_by' => $this->seller->id,
            'affiliate_id' => $this->affiliate->id,
            'customer_name' => 'زبون بإحالة المسوّق',
        ]);

        $this->actingAs($this->affiliate)
            ->get(route('admin.sales.orders.index'))
            ->assertOk()
            ->assertSee('زبون بإحالة المسوّق', false);

        $this->actingAs($this->affiliate)
            ->get(route('admin.sales.orders.show', $referred))
            ->assertOk();
    }

    public function test_a_manager_still_sees_everything(): void
    {
        $manager = $this->userWithRole('admin');

        $this->actingAs($manager)
            ->get(route('admin.sales.orders.index'))
            ->assertOk()
            ->assertSee('زبون المسوّق', false)
            ->assertSee('زبونة موظفة المبيعات', false);
    }

    public function test_an_admin_who_also_carries_the_marketer_role_still_sees_everything(): void
    {
        // الدور الإداري يسبق القيد، وإلا حَجَبَ صفةٌ ثانوية عن المدير عملَه.
        $manager = $this->userWithRole('admin');
        $manager->assignRole('affiliate');

        $this->actingAs($manager->fresh())
            ->get(route('admin.sales.orders.index'))
            ->assertOk()
            ->assertSee('زبونة موظفة المبيعات', false);
    }

    public function test_the_warehouse_keeps_full_visibility(): void
    {
        // المستودع يجهّز طلبات الجميع — قيدُ الدورين لا يمسّه.
        $warehouse = $this->userWithRole('warehouse');

        $this->actingAs($warehouse)
            ->get(route('admin.sales.orders.index'))
            ->assertOk()
            ->assertSee('زبون المسوّق', false)
            ->assertSee('زبونة موظفة المبيعات', false);
    }

    public function test_the_search_cannot_reveal_another_persons_order(): void
    {
        // البحث يعمل داخل النطاق لا فوقه. التحقّق برقم الطلب لا بنصّ البحث —
        // فالنصّ يعود في خانة البحث نفسها ولو لم يُطابق صفًّا.
        $this->affiliate->givePermissionTo('sales.orders.view');

        $this->actingAs($this->affiliate->fresh())
            ->get(route('admin.sales.orders.index', ['search' => 'زبونة موظفة المبيعات']))
            ->assertOk()
            ->assertDontSee($this->sellerOrder->number, false);
    }
}
