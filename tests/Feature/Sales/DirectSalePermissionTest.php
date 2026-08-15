<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Sales\Models\Order;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «مبيعات مباشرة» صلاحية مستقلّة لا مشتقّة.
 *
 * كانت السياسة `create && view`، فأيّ دور ينال العرض الكامل لسبب آخر تُفتح له
 * نقطة بيع كاملة (تحصيل نقدي وخصم مخزون فوري) بلا قصد. المسوّق تحديدًا يجب
 * أن ينشئ الطلبات دون أن يبيع مباشرةً.
 *
 * الاختبار يطرق **المسار** لا الزرّ: إخفاء الزرّ عرضٌ، والمنع يقع في السياسة.
 */
class DirectSalePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SalesPermissionSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_a_marketer_cannot_reach_direct_sales(): void
    {
        $marketer = $this->userWithRole('affiliate');

        $this->actingAs($marketer)->get(route('admin.sales.orders.direct.create'))->assertForbidden();
        $this->actingAs($marketer)->post(route('admin.sales.orders.direct.store'))->assertForbidden();
        $this->assertFalse($marketer->can('createDirect', Order::class));
    }

    public function test_the_marketer_keeps_creating_ordinary_orders(): void
    {
        // الطلب ليس المنع الكامل: عمل المسوّق جلب الطلبات، وهذا يبقى.
        $this->assertTrue($this->userWithRole('affiliate')->can('create', Order::class));
    }

    public function test_the_direct_sales_entry_points_disappear_for_a_marketer(): void
    {
        $marketer = $this->userWithRole('affiliate');

        // زرّ صفحة الطلبات والرابط الجانبي محكومان بالقدرة نفسها.
        $this->actingAs($marketer)
            ->get(route('admin.sales.orders.index'))
            ->assertOk()
            ->assertDontSee(route('admin.sales.orders.direct.create'), false);
    }

    public function test_managers_and_admins_still_have_it(): void
    {
        // لا يتضيّق وصول أحد كان يملكه: هذان وحدهما من كانا يمرّان بالاشتقاق القديم.
        foreach (['admin', 'manager'] as $role) {
            $this->assertTrue($this->userWithRole($role)->can('createDirect', Order::class), $role);
        }
    }

    public function test_holding_full_order_view_no_longer_grants_direct_sales(): void
    {
        // جوهر التغيير: الصلاحية لم تعد تُستنتج من غيرها.
        $user = $this->userWithRole('affiliate');
        $user->givePermissionTo('sales.orders.view');
        $user->forgetCachedPermissions();

        $this->assertTrue($user->fresh()->can('sales.orders.create'));
        $this->assertTrue($user->fresh()->can('sales.orders.view'));
        $this->assertFalse($user->fresh()->can('createDirect', Order::class));
    }
}
