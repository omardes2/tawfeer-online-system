<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تقارير المبيعات الثلاثة تعطي الرقم نفسه.
 *
 * كانت تختلف لليوم الواحد لأن كلًّا منها يجمع من مصدر آخر: «حسب الزبون» من
 * `orders.total` (وفيه رسوم التوصيل)، و«حسب الموظف» من `orders.subtotal` (قبل
 * الخصم) ويُسقط الطلبات بلا موظف، و«حسب المنتج» من بنود الأصناف. صار الثلاثة
 * على أساس واحد: سعر بيع البضاعة بعد الخصم، بلا توصيل ولا عمولات.
 */
class SalesReportsAgreeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function order(array $items, array $attrs = []): Order
    {
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_name' => 'زبون',
            'customer_phone' => '0599000000',
        ] + $attrs, $items, 2026);

        $order->update($attrs + ['status' => 'delivered']);

        return $order->refresh();
    }

    private function report(string $route): array
    {
        $res = $this->actingAs($this->admin)->get(route($route))->assertOk();

        return [
            'orders' => $res->viewData('totalOrders'),
            'sales' => round((float) $res->viewData('totalSales'), 2),
            'profit' => round((float) $res->viewData('totalProfit'), 2),
        ];
    }

    /**
     * الحالات الثلاث التي كانت تُفرّق الأرقام مجتمعةً: رسوم توصيل، وخصم على
     * البند، وطلب بلا موظف مبيعات.
     */
    public function test_the_three_sales_reports_report_identical_totals(): void
    {
        $employee = User::factory()->create(['branch_id' => Branch::default()->id, 'name' => 'موظف تجريبي']);
        $customer = Customer::factory()->create(['name' => 'زبون مسجَّل']);
        $variant = Product::factory()->create(['name' => 'منتج تجريبي'])->defaultVariant;
        $variant->update(['average_cost' => 60]);

        // طلب بموظف: خصم على البند + رسوم توصيل لا تخصّ المبيعات.
        $withEmployee = $this->order(
            [['variant_id' => $variant->id, 'qty' => 2, 'unit_price' => 100, 'discount' => 10]],
            ['assigned_to' => $employee->id, 'customer_id' => $customer->id, 'customer_name' => $customer->name],
        );
        $withEmployee->update(['shipping_total' => 25, 'total' => 215]);

        // طلب بلا موظف (المتجر الإلكتروني): كان يسقط من تقرير الموظفين وحده.
        $this->order([['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 100]]);

        // البيع = (2×100 − 10) + 100 = 290، والتكلفة = 3×60 = 180.
        $expected = ['orders' => 2, 'sales' => 290.0, 'profit' => 110.0];

        $this->assertSame($expected, $this->report('admin.reports.sales.by_customer'));
        $this->assertSame($expected, $this->report('admin.reports.sales.by_product'));
        $this->assertSame($expected, $this->report('admin.reports.sales.by_employee'));
    }

    /** صفّ «بلا موظف» هو ما يجعل مجموع صفحة الموظفين مساويًا لمبيعات الفترة. */
    public function test_the_employee_report_carries_an_unassigned_row(): void
    {
        $variant = Product::factory()->create()->defaultVariant;
        $this->order([['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 80]]);

        $rows = collect($this->actingAs($this->admin)
            ->get(route('admin.reports.sales.by_employee'))->assertOk()->viewData('rows'));

        $this->assertCount(1, $rows);
        $this->assertTrue($rows->first()['unassigned']);
        $this->assertSame(__('طلبات بلا موظف مبيعات'), $rows->first()['name']);

        // ويسقط الصفّ عند فلترة أشخاص بعينهم — لأنه ليس أحدهم.
        $other = User::factory()->create(['branch_id' => Branch::default()->id]);
        $filtered = collect($this->actingAs($this->admin)
            ->get(route('admin.reports.sales.by_employee', ['users' => [$other->id]]))
            ->assertOk()->viewData('rows'));

        $this->assertTrue($filtered->isEmpty());
    }

    /** القائمة المنسدلة تحت كل موظف: رقم التتبّع والصنف والكمية والبيع والربح لكل طلب. */
    public function test_each_employee_row_details_its_orders(): void
    {
        $employee = User::factory()->create(['branch_id' => Branch::default()->id, 'name' => 'موظف تجريبي']);
        $variant = Product::factory()->create(['name' => 'قميص قطن'])->defaultVariant;
        $variant->update(['average_cost' => 40]);

        $order = $this->order(
            [['variant_id' => $variant->id, 'qty' => 3, 'unit_price' => 90]],
            ['assigned_to' => $employee->id],
        );
        $order->update(['tracking_number' => 'TRK-9911']);

        $res = $this->actingAs($this->admin)->get(route('admin.reports.sales.by_employee'))->assertOk();
        $row = collect($res->viewData('rows'))->firstWhere('name', 'موظف تجريبي');

        $this->assertNotNull($row);
        $line = $row['orders']->first();

        $this->assertSame($order->number, $line['number']);
        $this->assertSame('TRK-9911', $line['tracking']);
        $this->assertSame('قميص قطن', $line['items'][0]['name']);
        $this->assertSame(3.0, $line['qty']);
        $this->assertSame(270.0, round($line['sale'], 2));
        $this->assertSame(150.0, round($line['profit'], 2)); // 270 − 3×40

        // ويظهر التفصيل في الصفحة نفسها.
        $res->assertSee('TRK-9911')->assertSee(__('رقم التتبع'), false);
    }

    /** الطلب الملغى أو المحذوف لا يدخل أي تقرير — فتبقى الثلاثة متطابقة. */
    public function test_cancelled_and_deleted_orders_are_excluded_everywhere(): void
    {
        $variant = Product::factory()->create()->defaultVariant;
        $variant->update(['average_cost' => 10]);

        $kept = $this->order([['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 100]]);
        $cancelled = $this->order([['variant_id' => $variant->id, 'qty' => 5, 'unit_price' => 100]]);
        $cancelled->update(['status' => 'cancelled']);
        $deleted = $this->order([['variant_id' => $variant->id, 'qty' => 7, 'unit_price' => 100]]);
        $deleted->delete();

        $expected = ['orders' => 1, 'sales' => 100.0, 'profit' => 90.0];

        $this->assertSame($expected, $this->report('admin.reports.sales.by_customer'));
        $this->assertSame($expected, $this->report('admin.reports.sales.by_product'));
        $this->assertSame($expected, $this->report('admin.reports.sales.by_employee'));
        $this->assertSame($kept->number, collect($this->actingAs($this->admin)
            ->get(route('admin.reports.sales.by_employee'))->viewData('rows'))
            ->first()['orders']->first()['number']);
    }
}
