<?php

namespace Tests\Feature\Returns;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Services\ReturnPostingService;
use App\Modules\Returns\Services\ReturnService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الترحيل المحاسبي للمرتجعات (ADR-012f): عكس الإيراد دائمًا، وعكس التكلفة للبضاعة
 * الصالحة المُعادة للمخزون فقط. بدونه يبقى الإيراد متضخّمًا والمخزون ناقصًا دفتريًا.
 */
class ReturnAccountingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private ReturnService $svc;

    private AccountingService $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
        $this->svc = app(ReturnService::class);
        $this->accounting = app(AccountingService::class);
    }

    private function balance(string $code): float
    {
        return $this->accounting->accountBalance(Account::where('code', $code)->firstOrFail());
    }

    /**
     * قيمة المردودات كرقم موجب. «مردودات المبيعات 4030» حساب مقابل للإيراد: رصيده مدين،
     * فيظهر سالبًا تحت الطبيعة الدائنة للإيراد — وهو ما يجعله يخصم من الإيراد تلقائيًا.
     */
    private function returns(): float
    {
        return -$this->balance('4030');
    }

    /** صافي الإيراد = إيراد المبيعات + المردودات (المقابل سالب). */
    private function netRevenue(): float
    {
        return $this->balance('4010') + $this->balance('4030');
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    /** @return array{0: Order, 1: ProductVariant} */
    private function deliveredOrder(float $qty = 2, float $price = 100, float $cost = 60): array
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => $price]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => $price]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 50, $cost);

        $os = app(OrderService::class);
        $order = $os->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'x', 'customer_phone' => '0500000000',
        ], [['variant_id' => $variant->fresh()->id, 'qty' => $qty, 'unit_price' => $price, 'discount' => 0]], 2026);

        $os->confirm($order);
        $os->reserveStock($order->fresh());
        $os->startPreparing($order->fresh());
        $os->markReady($order->fresh());
        $os->ship($order->fresh());
        $os->deliver($order->fresh());

        return [$order->fresh('items'), $variant->fresh()];
    }

    private function runReturn(Order $order, float $qty, string $route): void
    {
        $r = $this->svc->create($order, ['type' => 'return', 'reason_code' => 'damaged', 'resolution' => 'no_refund'],
            [['order_item_id' => $order->items->first()->id, 'qty' => $qty]], 2026, $this->actor('sales'));

        $this->svc->approve($r, $this->actor('sales_supervisor'));
        $this->svc->receive($r, $this->actor('warehouse'));
        $this->svc->inspect($r, [$r->items->first()->id => [
            'inspection_result' => $route === 'restock' ? 'sellable' : 'damaged',
            'inventory_route' => $route,
        ]], $this->actor('warehouse'));
        $this->svc->complete($r, $this->actor('finance'));
    }

    public function test_full_restock_return_reverses_revenue_and_cost(): void
    {
        [$order] = $this->deliveredOrder(qty: 2, price: 100, cost: 60);

        // بعد البيع: ذمم 200، إيراد 200، تكلفة 120.
        $this->assertEqualsWithDelta(200, $this->balance('1050'), 0.01);
        $this->assertEqualsWithDelta(120, $this->balance('6000'), 0.01);

        $this->runReturn($order, 2, 'restock');

        // مردودات المبيعات 200 (مدين) والمديونية أُقفلت.
        $this->assertEqualsWithDelta(200, $this->returns(), 0.01);
        $this->assertEqualsWithDelta(0, $this->balance('1050'), 0.01);
        // صافي الإيراد = 200 − 200 = 0، والتكلفة عادت للمخزون ⇒ COGS صفر.
        $this->assertEqualsWithDelta(0, $this->netRevenue(), 0.01);
        $this->assertEqualsWithDelta(0, $this->balance('6000'), 0.01);
    }

    public function test_partial_restock_return_reverses_proportionally(): void
    {
        [$order] = $this->deliveredOrder(qty: 2, price: 100, cost: 60);

        $this->runReturn($order, 1, 'restock');

        $this->assertEqualsWithDelta(100, $this->returns(), 0.01);      // نصف القيمة
        $this->assertEqualsWithDelta(100, $this->netRevenue(), 0.01);   // صافي الإيراد 100
        $this->assertEqualsWithDelta(100, $this->balance('1050'), 0.01); // بقي نصف المديونية
        $this->assertEqualsWithDelta(60, $this->balance('6000'), 0.01);  // بقيت تكلفة وحدة
    }

    /** البضاعة التالفة: يُعكس الإيراد ولا تعود قيمتها للمخزون (تبقى خسارة في التكلفة). */
    public function test_damaged_return_reverses_revenue_but_keeps_cost_as_loss(): void
    {
        [$order] = $this->deliveredOrder(qty: 2, price: 100, cost: 60);

        $this->runReturn($order, 2, 'damaged');

        $this->assertEqualsWithDelta(200, $this->returns(), 0.01);    // الإيراد عُكس
        $this->assertEqualsWithDelta(0, $this->balance('1050'), 0.01);
        $this->assertEqualsWithDelta(120, $this->balance('6000'), 0.01); // التكلفة تبقى خسارة
    }

    /** الخصم يُحترم: عكس الإيراد بالقيمة الصافية المباعة لا بالسعر القائم. */
    public function test_discounted_order_return_reverses_net_value(): void
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => 100]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => 100]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 50, 50);

        $os = app(OrderService::class);
        // 2 × 100 بخصم 40 ⇒ صافي 160 (80 للوحدة).
        $order = $os->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'x', 'customer_phone' => '0500000000',
        ], [['variant_id' => $variant->fresh()->id, 'qty' => 2, 'unit_price' => 100, 'discount' => 40]], 2026);
        $os->confirm($order);
        $os->reserveStock($order->fresh());
        $os->startPreparing($order->fresh());
        $os->markReady($order->fresh());
        $os->ship($order->fresh());
        $os->deliver($order->fresh());

        $this->assertEqualsWithDelta(160, $this->balance('1050'), 0.01);

        $this->runReturn($order->fresh('items'), 2, 'restock');

        $this->assertEqualsWithDelta(160, $this->returns(), 0.01); // الصافي لا 200
        $this->assertEqualsWithDelta(0, $this->balance('1050'), 0.01);
    }

    /** الترحيل يقع مرّة واحدة (مرجع القيد محفوظ على طلب الإرجاع). */
    public function test_return_posting_is_recorded_once(): void
    {
        [$order] = $this->deliveredOrder(qty: 2);

        $this->runReturn($order, 2, 'restock');

        $request = ReturnRequest::where('order_id', $order->id)->firstOrFail();
        $this->assertNotNull($request->revenue_entry_id);
        $this->assertNotNull($request->cogs_entry_id);

        app(ReturnPostingService::class)->post($request->fresh());

        $this->assertEqualsWithDelta(200, $this->returns(), 0.01); // بلا تكرار
    }
}
