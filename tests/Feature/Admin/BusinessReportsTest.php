<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    private function withRole(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    private array $routes = [
        'admin.reports.sales.by_customer',
        'admin.reports.sales.by_product',
        'admin.reports.sales.by_employee',
        'admin.reports.sales.by_affiliate',
        'admin.reports.receivables.customers',
        'admin.reports.receivables.suppliers',
    ];

    public function test_all_report_pages_render_for_admin(): void
    {
        foreach ($this->routes as $r) {
            $this->actingAs($this->admin())->get(route($r))->assertOk();
        }
    }

    public function test_reports_gated_by_permission(): void
    {
        // موظف المبيعات لا يملك صلاحيات التقارير المجمّعة.
        $sales = $this->withRole('sales');
        $this->actingAs($sales)->get(route('admin.reports.sales.by_customer'))->assertForbidden();
        $this->actingAs($sales)->get(route('admin.reports.receivables.customers'))->assertForbidden();

        // المحاسب يرى كشوف الحسابات لا تقارير المبيعات.
        $accountant = $this->withRole('accountant');
        $this->actingAs($accountant)->get(route('admin.reports.receivables.suppliers'))->assertOk();
        $this->actingAs($accountant)->get(route('admin.reports.sales.by_product'))->assertForbidden();
    }

    public function test_sales_reports_show_aggregates(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $employee = User::factory()->create(['branch_id' => Branch::default()->id, 'name' => 'موظف تجريبي']);
        $customer = Customer::factory()->create(['name' => 'زبون تجريبي']);
        $variant = Product::factory()->create(['name' => 'منتج تجريبي'])->defaultVariant;

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_id' => $customer->id, 'customer_name' => $customer->name, 'customer_phone' => '0599000000',
        ], [['variant_id' => $variant->id, 'qty' => 2, 'unit_price' => 100]], 2026);
        $order->update(['assigned_to' => $employee->id, 'status' => 'delivered']);

        $this->actingAs($this->admin())->get(route('admin.reports.sales.by_customer'))
            ->assertOk()->assertSee('زبون تجريبي')->assertSee('200.00');

        $this->actingAs($this->admin())->get(route('admin.reports.sales.by_product'))
            ->assertOk()->assertSee('منتج تجريبي');

        $this->actingAs($this->admin())->get(route('admin.reports.sales.by_employee'))
            ->assertOk()->assertSee('موظف تجريبي')->assertSee('200.00');
    }

    /** تقرير المسوّقين: يجمّع على affiliate_id ولا يخلط موظفي المبيعات به. */
    public function test_sales_by_affiliate_report_lists_marketers_only(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $employee = User::factory()->create(['branch_id' => Branch::default()->id, 'name' => 'موظف تجريبي']);
        $marketer = User::factory()->create(['branch_id' => Branch::default()->id, 'name' => 'مسوّق تجريبي']);
        $variant = Product::factory()->create(['name' => 'صنف'])->defaultVariant;

        $make = function (array $attrs) use ($warehouse, $variant) {
            $o = app(OrderService::class)->create([
                'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
                'customer_name' => 'زبون', 'customer_phone' => '0599000000',
            ], [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 300]], 2026);
            $o->update($attrs + ['status' => 'delivered']);
        };

        $make(['affiliate_id' => $marketer->id]);
        $make(['assigned_to' => $employee->id]);

        // صفحة المسوّقين: المسوّق فقط.
        $this->actingAs($this->admin())->get(route('admin.reports.sales.by_affiliate'))
            ->assertOk()->assertSee('مسوّق تجريبي')->assertDontSee('موظف تجريبي');

        // وصفحة الموظفين: الموظف فقط.
        $this->actingAs($this->admin())->get(route('admin.reports.sales.by_employee'))
            ->assertOk()->assertSee('موظف تجريبي')->assertDontSee('مسوّق تجريبي');
    }

    public function test_csv_export_streams_arabic(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $customer = Customer::factory()->create(['name' => 'زبون التصدير']);
        $variant = Product::factory()->create()->defaultVariant;
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_id' => $customer->id, 'customer_name' => $customer->name, 'customer_phone' => '0599000000',
        ], [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 60]], 2026);
        $order->update(['status' => 'delivered']);

        $res = $this->actingAs($this->admin())->get(route('admin.reports.sales.by_customer', ['export' => 'csv']));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('content-type'));
        $this->assertStringContainsString('زبون التصدير', $res->streamedContent());
    }

    public function test_date_range_filters_sales(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $recent = Customer::factory()->create(['name' => 'زبون حالي']);
        $old = Customer::factory()->create(['name' => 'زبون قديم']);

        foreach ([[$recent, now()], [$old, now()->subYear()]] as [$cust, $when]) {
            $o = app(OrderService::class)->create([
                'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
                'customer_id' => $cust->id, 'customer_name' => $cust->name, 'customer_phone' => '0599000000',
            ], [['variant_id' => Product::factory()->create()->defaultVariant->id, 'qty' => 1, 'unit_price' => 50]], 2026);
            $o->forceFill(['status' => 'delivered', 'created_at' => $when])->save();
        }

        // النطاق الافتراضي «هذا الشهر»: يظهر الحالي دون القديم.
        $this->actingAs($this->admin())->get(route('admin.reports.sales.by_customer'))
            ->assertOk()->assertSee('زبون حالي')->assertDontSee('زبون قديم');

        // نطاق «هذا العام» يشمل القديم إن كان ضمن نفس السنة، أو مخصّص واسع يشمل كليهما.
        $this->actingAs($this->admin())->get(route('admin.reports.sales.by_customer', [
            'range' => 'custom', 'from' => now()->subYears(2)->toDateString(), 'to' => now()->toDateString(),
        ]))->assertOk()->assertSee('زبون قديم');
    }
}
