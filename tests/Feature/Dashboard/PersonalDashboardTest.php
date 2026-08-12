<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الشاشة الرئيسية تتبع دور فاتحها: من يتابع الفريق يرى النظرة العامة، ومن يبيع
 * لنفسه يرى أرقامه هو — ولا يرى أرقام زملائه ولو بالخطأ.
 */
class PersonalDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user;
    }

    private function orderFor(User $user, string $column, float $total, float $shipping = 0): Order
    {
        return Order::factory()->create([
            $column => $user->id,
            'branch_id' => Branch::default()->id,
            'status' => 'confirmed',
            'subtotal' => $total - $shipping,
            'shipping_total' => $shipping,
            'total' => $total,
        ]);
    }

    public function test_admin_still_gets_the_overview_dashboard(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewIs('admin.dashboard.index');
    }

    public function test_sales_employee_gets_the_personal_dashboard(): void
    {
        $this->actingAs($this->userWithRole('sales'))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewIs('admin.dashboard.personal')
            ->assertViewHas('earnerType', 'sales');
    }

    public function test_affiliate_gets_the_personal_dashboard_as_an_affiliate_earner(): void
    {
        $this->actingAs($this->userWithRole('affiliate'))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewIs('admin.dashboard.personal')
            ->assertViewHas('earnerType', 'affiliate');
    }

    /** أرقام الشاشة الشخصية تخصّ صاحبها — طلب زميله لا يدخل حسابه. */
    public function test_personal_totals_exclude_other_peoples_orders(): void
    {
        $mine = $this->userWithRole('sales');
        $colleague = $this->userWithRole('sales');

        $this->orderFor($mine, 'assigned_to', 120, 20);
        $this->orderFor($colleague, 'assigned_to', 500, 20);

        $response = $this->actingAs($mine)->get(route('admin.dashboard'))->assertOk();

        $this->assertSame(1, $response->viewData('todayOrders'));
        // 120 − 20 توصيل = 100، وهو أساس العمولة.
        $this->assertSame(100.0, $response->viewData('todaySales'));
    }

    /** المسوّق يُقاس بعمود `affiliate_id` لا `assigned_to`. */
    public function test_affiliate_totals_follow_the_affiliate_column(): void
    {
        $affiliate = $this->userWithRole('affiliate');

        $this->orderFor($affiliate, 'affiliate_id', 200, 20);
        $this->orderFor($affiliate, 'assigned_to', 900, 20);

        $response = $this->actingAs($affiliate)->get(route('admin.dashboard'))->assertOk();

        $this->assertSame(1, $response->viewData('todayOrders'));
        $this->assertSame(180.0, $response->viewData('todaySales'));
    }

    /** الطلبات المتوقّفة تُعرض أولًا — العمل يبدأ من مشكلة لا من رقم. */
    public function test_stalled_orders_are_surfaced(): void
    {
        $user = $this->userWithRole('sales');

        $this->orderFor($user, 'assigned_to', 100)->update(['status' => 'awaiting_confirmation']);
        $this->orderFor($user, 'assigned_to', 100)->update(['status' => 'delivered']);

        $attention = $this->actingAs($user)->get(route('admin.dashboard'))->viewData('needsAttention');

        $this->assertCount(1, $attention);
        $this->assertSame('awaiting_confirmation', $attention->first()->status);
    }
}
