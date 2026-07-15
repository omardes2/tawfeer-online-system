<?php

namespace Tests\Feature\Reporting;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Reporting\Services\ReportingService;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private ReportingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
        $this->svc = app(ReportingService::class);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    private function deliveredOrder(?User $rep = null, ?User $affiliate = null, float $price = 100, float $cost = 60, float $qty = 2): Order
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => $price]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => $price]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 50, $cost);

        $os = app(OrderService::class);
        $order = $os->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'x', 'customer_phone' => '0500000000',
            'assigned_to' => $rep?->id,
        ], [['variant_id' => $variant->fresh()->id, 'qty' => $qty, 'unit_price' => $price, 'discount' => 0]], 2026);
        if ($affiliate) {
            $order->update(['affiliate_id' => $affiliate->id]);
        }
        $order->items->each->update(['wholesale_cost_snapshot' => $cost]);

        $os->confirm($order);
        $os->reserveStock($order->fresh());
        $os->startPreparing($order->fresh());
        $os->markReady($order->fresh());
        $os->ship($order->fresh());
        $os->deliver($order->fresh());

        return $order->fresh();
    }

    private function month(): DateRange
    {
        return DateRange::resolve('month');
    }

    public function test_executive_aggregates_are_read_from_live_data(): void
    {
        $this->deliveredOrder($this->actor('sales'), null, price: 100, qty: 2);
        $data = $this->svc->executive($this->month());

        $this->assertEquals(1, $data['orders']);
        $this->assertEquals(200.0, $data['sales_total']);
        $this->assertEquals(1, $data['delivered']);
        $this->assertGreaterThan(0, $data['commissions']); // 1% مُستحقّ
    }

    public function test_sales_employee_performance(): void
    {
        $rep = $this->actor('sales');
        $this->deliveredOrder($rep, null, price: 100, qty: 2);

        $row = $this->svc->salesEmployees($this->month())->firstWhere('id', $rep->id);
        $this->assertNotNull($row);
        $this->assertEquals(1, $row->orders_count);
        $this->assertEquals(200.0, (float) $row->sales_total);
        $this->assertEquals(2.0, (float) $row->commissions); // 1% من 200
    }

    public function test_marketer_performance_uses_affiliate_earnings(): void
    {
        $aff = $this->actor('affiliate');
        $this->deliveredOrder($this->actor('sales'), $aff, price: 100, cost: 60, qty: 2);

        $row = $this->svc->marketers($this->month())->firstWhere('id', $aff->id);
        $this->assertNotNull($row);
        $this->assertEquals(80.0, (float) $row->earnings); // هامش (100−60)*2
    }

    public function test_product_performance(): void
    {
        $this->deliveredOrder($this->actor('sales'), null, price: 100, qty: 3);
        $row = $this->svc->products($this->month())->first();

        $this->assertEquals(3.0, (float) $row->qty);
        $this->assertEquals(300.0, (float) $row->revenue);
    }

    public function test_date_range_filters_exclude_out_of_range(): void
    {
        $this->deliveredOrder($this->actor('sales'));
        $past = DateRange::resolve('custom', '2020-01-01', '2020-12-31');

        $this->assertEquals(0, $this->svc->executive($past)['orders']);
        $this->assertEquals(1, $this->svc->executive($this->month())['orders']);
    }

    public function test_finance_and_delivery_and_returns_shapes(): void
    {
        $data = $this->svc->finance($this->month());
        $this->assertArrayHasKey('cod_collected', $data);
        $this->assertArrayHasKey('net', $data);

        $delivery = $this->svc->delivery($this->month());
        $this->assertArrayHasKey('by_status', $delivery);

        $returns = $this->svc->returns($this->month());
        $this->assertArrayHasKey('by_reason', $returns);
    }

    // ---- الواجهة والتصدير ----
}
