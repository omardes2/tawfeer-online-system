<?php

namespace Tests\Feature\Reporting;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Reporting\Services\ReportingService;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أرقام اللوحة تطابق الدفاتر: الطلب المحذوف يختفي منها كما اختفت قيوده، والمبالغ
 * صافية من رسوم التوصيل — وهو الأساس نفسه الذي تُحتسب عليه العمولات.
 */
class DashboardBoardsTest extends TestCase
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

    private function order(User $owner, string $column, float $total, float $shipping, ?string $when = null): Order
    {
        return Order::factory()->create([
            $column => $owner->id,
            'branch_id' => Branch::default()->id,
            'status' => 'confirmed',
            'subtotal' => $total - $shipping,
            'shipping_total' => $shipping,
            'total' => $total,
            'created_at' => $when ? now()->parse($when) : now(),
        ]);
    }

    private function reports(): ReportingService
    {
        return app(ReportingService::class);
    }

    /** المبالغ بلا رسوم التوصيل — رسوم التوصيل ليست إيرادًا لنا. */
    public function test_board_amounts_exclude_delivery_fees(): void
    {
        $seller = $this->userWithRole('sales');
        $this->order($seller, 'assigned_to', 120, 20);

        $row = $this->reports()->earnerBoard('assigned_to', ['sales'])->firstWhere('name', $seller->name);

        $this->assertSame(1, $row['orders_today']);
        $this->assertSame(100.0, $row['sales_today']);
    }

    /** الطلب المحذوف تُعكَس قيوده، فيجب أن يختفي من التقارير أيضًا. */
    public function test_soft_deleted_orders_leave_the_reports(): void
    {
        $seller = $this->userWithRole('sales');
        $kept = $this->order($seller, 'assigned_to', 220, 20);
        $removed = $this->order($seller, 'assigned_to', 520, 20);

        $before = $this->reports()->earnerBoard('assigned_to', ['sales'])->firstWhere('name', $seller->name);
        $this->assertSame(700.0, $before['sales_today']);

        $removed->delete();

        $after = $this->reports()->earnerBoard('assigned_to', ['sales'])->firstWhere('name', $seller->name);
        $this->assertSame(200.0, $after['sales_today']);
        $this->assertSame(1, $after['orders_today']);

        // ونفس الشيء في مؤشّرات اللوحة العامة.
        $kpis = $this->reports()->kpis(DateRange::resolve('day'));
        $this->assertSame(200.0, $kpis['sales']['total']);
        $this->assertSame(1, $kpis['sales']['orders']);
        $this->assertNotNull($kept->fresh());
    }

    /** الأعمدة الثلاثة تقيس ثلاث فترات مختلفة لا فترة واحدة. */
    public function test_today_yesterday_and_month_are_measured_separately(): void
    {
        $seller = $this->userWithRole('sales');

        $this->order($seller, 'assigned_to', 110, 10);                                   // اليوم: 100
        $this->order($seller, 'assigned_to', 60, 10, now()->subDay()->toDateTimeString()); // أمس: 50

        $row = $this->reports()->earnerBoard('assigned_to', ['sales'])->firstWhere('name', $seller->name);

        $this->assertSame(100.0, $row['sales_today']);
        $this->assertSame(50.0, $row['sales_yesterday']);
        // كلاهما داخل الشهر الجاري ما لم يقع أمس في الشهر السابق.
        $expectedMonth = now()->isSameMonth(now()->subDay()) ? 150.0 : 100.0;
        $this->assertSame($expectedMonth, $row['sales_month']);
    }

    /** المسوّق يُقاس بعمود `affiliate_id`، وموظف المبيعات بـ`assigned_to`. */
    public function test_each_board_reads_its_own_column(): void
    {
        $seller = $this->userWithRole('sales');
        $affiliate = $this->userWithRole('affiliate');

        $this->order($seller, 'assigned_to', 100, 0);
        $this->order($affiliate, 'affiliate_id', 300, 0);

        $sales = $this->reports()->earnerBoard('assigned_to', ['sales']);
        $affiliates = $this->reports()->earnerBoard('affiliate_id', ['affiliate']);

        $this->assertSame(100.0, $sales->firstWhere('name', $seller->name)['sales_today']);
        $this->assertSame(300.0, $affiliates->firstWhere('name', $affiliate->name)['sales_today']);
        $this->assertNull($affiliates->firstWhere('name', $seller->name));
    }

    /** صاحب الدور يظهر بأصفار — غياب الاسم يُقرأ خطأً على أنه غياب الموظف. */
    public function test_people_without_orders_still_appear(): void
    {
        $idle = $this->userWithRole('sales');

        $row = $this->reports()->earnerBoard('assigned_to', ['sales'])->firstWhere('name', $idle->name);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['orders_today']);
        $this->assertSame(0.0, $row['sales_month']);
    }

    /** الطلب الملغى أو المسودّة لا يُحسب مبيعات. */
    public function test_cancelled_and_draft_orders_are_excluded(): void
    {
        $seller = $this->userWithRole('sales');

        $this->order($seller, 'assigned_to', 100, 0)->update(['status' => 'cancelled']);
        $this->order($seller, 'assigned_to', 200, 0)->update(['status' => 'draft']);
        $this->order($seller, 'assigned_to', 300, 0);

        $row = $this->reports()->earnerBoard('assigned_to', ['sales'])->firstWhere('name', $seller->name);

        $this->assertSame(300.0, $row['sales_today']);
        $this->assertSame(1, $row['orders_today']);
    }

    /** اللوحة تعرض الجدولين لمن يملك صلاحية تقارير المبيعات. */
    public function test_dashboard_exposes_both_boards(): void
    {
        $response = $this->actingAs($this->userWithRole('admin'))
            ->get(route('admin.dashboard'))->assertOk();

        $this->assertNotNull($response->viewData('salesBoard'));
        $this->assertNotNull($response->viewData('affiliateBoard'));
    }
}
