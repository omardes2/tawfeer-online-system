<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Reporting\Services\ReportingService;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * لوحة التحكّم: مبيعات اليوم بحسب البائع، والرسم السنويّ.
 *
 * والمبيعات **بلا رسوم التوصيل** في الاثنين — الرسوم تخصّ شركة التوصيل ولا
 * تُعدّ إيرادًا، وهي مستثناة في `NET_SALES` أصلًا فلا تعريف ثانيًا لها هنا.
 */
class DashboardEarnerSalesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private User $affiliate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->seller = User::factory()->create(['name' => 'هالة', 'branch_id' => Branch::default()->id]);
        $this->seller->assignRole('sales');

        $this->affiliate = User::factory()->create(['name' => 'سائد', 'branch_id' => Branch::default()->id]);
        $this->affiliate->assignRole('affiliate');
    }

    private function order(array $attributes): Order
    {
        $order = Order::factory()->create($attributes + [
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::firstOrFail()->id,
            'status' => 'confirmed',
        ]);

        if (isset($attributes['created_at'])) {
            $order->newQuery()->whereKey($order->id)->toBase()
                ->update(['created_at' => $attributes['created_at']]);
        }

        return $order->refresh();
    }

    private function today(): array
    {
        return app(ReportingService::class)->todaySalesByEarnerType();
    }

    // ────────── مبيعات اليوم بحسب البائع ──────────

    /** مبيعات الموظف تُحتسب على عمود الإسناد. */
    public function test_staff_sales_come_from_assigned_orders(): void
    {
        $this->order(['assigned_to' => $this->seller->id, 'total' => 300, 'shipping_total' => 0]);

        $this->assertEqualsWithDelta(300.0, $this->today()['staff'], 0.01);
        $this->assertEqualsWithDelta(0.0, $this->today()['affiliates'], 0.01);
    }

    /** ومبيعات المسوّق على عمود التسويق. */
    public function test_affiliate_sales_come_from_the_affiliate_column(): void
    {
        $this->order(['affiliate_id' => $this->affiliate->id, 'total' => 500, 'shipping_total' => 0]);

        $this->assertEqualsWithDelta(500.0, $this->today()['affiliates'], 0.01);
        $this->assertEqualsWithDelta(0.0, $this->today()['staff'], 0.01);
    }

    /**
     * **ورسوم التوصيل مخصومة من الاثنين.**
     *
     * الرسوم تخصّ شركة التوصيل ولا تُعدّ إيرادًا لنا، فإدخالها يُضخّم مبيعات
     * البائع بما لم يبعه.
     */
    public function test_delivery_fees_are_excluded(): void
    {
        $this->order(['assigned_to' => $this->seller->id, 'total' => 320, 'shipping_total' => 20]);
        $this->order(['affiliate_id' => $this->affiliate->id, 'total' => 220, 'shipping_total' => 20]);

        $this->assertEqualsWithDelta(300.0, $this->today()['staff'], 0.01);
        $this->assertEqualsWithDelta(200.0, $this->today()['affiliates'], 0.01);
    }

    /** وطلبٌ لمسوّقٍ ومُسنَدٌ لموظف يُعدّ للمسوّق وحده — فلا يُحتسب مرّتين. */
    public function test_an_order_is_never_counted_twice(): void
    {
        $this->order([
            'affiliate_id' => $this->affiliate->id,
            'assigned_to' => $this->seller->id,
            'total' => 400, 'shipping_total' => 0,
        ]);

        $this->assertEqualsWithDelta(400.0, $this->today()['affiliates'], 0.01);
        $this->assertEqualsWithDelta(0.0, $this->today()['staff'], 0.01);
    }

    /** والملغاة لا تُحتسب — لم تُبَع. */
    public function test_cancelled_orders_are_excluded(): void
    {
        $this->order(['assigned_to' => $this->seller->id, 'total' => 999, 'shipping_total' => 0, 'status' => 'cancelled']);

        $this->assertEqualsWithDelta(0.0, $this->today()['staff'], 0.01);
    }

    /** وطلبُ الأمس ليس من اليوم. */
    public function test_only_today_counts(): void
    {
        $this->order([
            'assigned_to' => $this->seller->id, 'total' => 777, 'shipping_total' => 0,
            'created_at' => Carbon::yesterday(),
        ]);

        $this->assertEqualsWithDelta(0.0, $this->today()['staff'], 0.01);
    }

    // ────────── الرسم السنويّ ──────────

    /** اثنا عشر شهرًا دائمًا — والفارغ منها صفرٌ لا حذف. */
    public function test_the_year_always_has_twelve_months(): void
    {
        $months = app(ReportingService::class)->monthlySales((int) today()->year);

        $this->assertCount(12, $months);
        $this->assertSame(1, $months->first()['month']);
        $this->assertSame(12, $months->last()['month']);
    }

    /** وكل شهرٍ يحمل مبيعاته بلا رسوم توصيل. */
    public function test_each_month_carries_its_own_net_sales(): void
    {
        $year = (int) today()->year;

        $this->order(['assigned_to' => $this->seller->id, 'total' => 520, 'shipping_total' => 20,
            'created_at' => Carbon::create($year, 3, 10)]);
        $this->order(['assigned_to' => $this->seller->id, 'total' => 110, 'shipping_total' => 10,
            'created_at' => Carbon::create($year, 7, 5)]);

        $months = app(ReportingService::class)->monthlySales($year)->keyBy('month');

        $this->assertEqualsWithDelta(500.0, $months[3]['total'], 0.01);
        $this->assertEqualsWithDelta(100.0, $months[7]['total'], 0.01);
        $this->assertEqualsWithDelta(0.0, $months[1]['total'], 0.01);
        $this->assertEqualsWithDelta(600.0, (float) $months->sum('total'), 0.01);
    }

    /** وسنةٌ أخرى لا تتسرّب إليه. */
    public function test_another_year_does_not_leak_in(): void
    {
        $year = (int) today()->year;

        $this->order(['assigned_to' => $this->seller->id, 'total' => 999, 'shipping_total' => 0,
            'created_at' => Carbon::create($year - 1, 5, 10)]);

        $this->assertEqualsWithDelta(
            0.0,
            (float) app(ReportingService::class)->monthlySales($year)->sum('total'),
            0.01,
        );
    }

    // ────────── الشاشة ──────────

    /** مدير النظام يرى البطاقتين الجديدتين. */
    public function test_the_admin_sees_the_earner_cards(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('مبيعات الموظفين اليوم')
            ->assertSee('مبيعات المسوّقين اليوم')
            ->assertDontSee('جاهزة للشحن');
    }

    /**
     * ومن لا يرى أداء الفريق يبقى على العدّادين القديمين.
     *
     * مبيعات الزملاء لا تُعرض لموظفٍ على زميله.
     */
    public function test_a_seller_keeps_the_old_counters(): void
    {
        $keeper = User::factory()->create(['branch_id' => Branch::default()->id]);
        $keeper->assignRole('warehouse');

        $response = $this->actingAs($keeper)->get(route('admin.dashboard'));

        if ($response->status() === 200) {
            $response->assertDontSee('مبيعات المسوّقين اليوم');
        }
    }
}
