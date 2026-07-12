<?php

namespace Tests\Feature\Returns;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\PaymentMethod;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Payment\Models\Payment;
use App\Modules\Returns\Services\ReturnService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Shipping\Models\Shipment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReturnRmaTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private ReturnService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
        $this->svc = app(ReturnService::class);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    private function variant(float $price = 100, float $cost = 60, float $stock = 50): ProductVariant
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => $price]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => $price]);
        app(InventoryService::class)->receive($variant, $this->warehouse, $stock, $cost);

        return $variant->fresh();
    }

    /** @return array{0: Order, 1: ProductVariant, 2: User} */
    private function deliveredOrder(float $qty = 2, float $price = 100, float $cost = 60): array
    {
        $variant = $this->variant($price, $cost);
        $rep = $this->actor('sales');
        $os = app(OrderService::class);

        $order = $os->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'x', 'customer_phone' => '0500000000',
            'assigned_to' => $rep->id,
        ], [['variant_id' => $variant->id, 'qty' => $qty, 'unit_price' => $price, 'discount' => 0]], 2026);
        $order->items->each->update(['wholesale_cost_snapshot' => $cost]);

        $os->confirm($order);
        $os->reserveStock($order->fresh());
        $os->startPreparing($order->fresh());
        $os->markReady($order->fresh());
        $os->ship($order->fresh());
        $os->deliver($order->fresh());

        return [$order->fresh('items'), $variant, $rep];
    }

    private function stock(ProductVariant $variant): InventoryStock
    {
        return InventoryStock::where('variant_id', $variant->id)->where('warehouse_id', $this->warehouse->id)->first();
    }

    private function line(Order $order): OrderItem
    {
        return $order->items->first();
    }

    // ---- سير العمل والمخزون ----

    public function test_full_return_restocks_reverses_commission_and_sets_order_returned(): void
    {
        [$order, $variant] = $this->deliveredOrder(qty: 2);
        $this->assertEquals(48.0, (float) $this->stock($variant)->on_hand); // بعد الشحن

        $r = $this->svc->create($order, ['type' => 'return', 'reason_code' => 'damaged', 'resolution' => 'no_refund'],
            [['order_item_id' => $this->line($order)->id, 'qty' => 2]], 2026, $this->actor('sales'));

        $this->svc->approve($r, $this->actor('sales_supervisor'));
        $this->svc->receive($r, $this->actor('warehouse'));
        $this->svc->inspect($r, [$r->items->first()->id => ['inspection_result' => 'sellable', 'inventory_route' => 'restock']], $this->actor('warehouse'));
        $this->svc->complete($r, $this->actor('finance'));

        $this->assertEquals('completed', $r->fresh()->status);
        $this->assertEquals(50.0, (float) $this->stock($variant)->on_hand);            // أُعيدت للمخزون
        $this->assertEquals('returned', $order->fresh()->status);
        $this->assertEquals(2.0, (float) $this->line($order->fresh())->returned_qty);
        $this->assertEquals('reversed', CommissionEntry::where('order_id', $order->id)->where('entry_type', 'accrual')->first()->state);
    }

    public function test_partial_return_marks_partially_returned_and_adjusts_commission(): void
    {
        [$order, $variant] = $this->deliveredOrder(qty: 2);

        $r = $this->svc->create($order, ['type' => 'return', 'reason_code' => 'changed_mind', 'resolution' => 'no_refund'],
            [['order_item_id' => $this->line($order)->id, 'qty' => 1]], 2026, $this->actor('sales'));
        $this->svc->approve($r, $this->actor('sales_supervisor'));
        $this->svc->receive($r, $this->actor('warehouse'));
        $this->svc->inspect($r, [$r->items->first()->id => ['inspection_result' => 'sellable', 'inventory_route' => 'restock']], $this->actor('warehouse'));
        $this->svc->complete($r, $this->actor('finance'));

        $this->assertEquals('partially_returned', $order->fresh()->status);
        $this->assertEquals(1.0, (float) $this->line($order->fresh())->returned_qty);
        $this->assertEquals(49.0, (float) $this->stock($variant)->on_hand); // 48 + 1
        $adj = CommissionEntry::where('order_id', $order->id)->where('entry_type', 'adjustment')->first();
        $this->assertNotNull($adj);
        $this->assertEquals('-1.00', $adj->amount); // نصف عمولة 2.00
    }

    public function test_damaged_route_goes_to_damaged_bucket_not_on_hand(): void
    {
        [$order, $variant] = $this->deliveredOrder(qty: 2);

        $r = $this->svc->create($order, ['type' => 'return', 'reason_code' => 'damaged', 'resolution' => 'no_refund'],
            [['order_item_id' => $this->line($order)->id, 'qty' => 2]], 2026, $this->actor('sales'));
        $this->svc->approve($r, $this->actor('sales_supervisor'));
        $this->svc->receive($r, $this->actor('warehouse'));
        $this->svc->inspect($r, [$r->items->first()->id => ['inspection_result' => 'damaged', 'inventory_route' => 'damaged']], $this->actor('warehouse'));
        $this->svc->complete($r, $this->actor('finance'));

        $this->assertEquals(48.0, (float) $this->stock($variant)->on_hand); // لم تُعد للبيع
        $this->assertEquals(2.0, (float) $this->stock($variant)->damaged);
    }

    public function test_every_inventory_movement_is_logged(): void
    {
        [$order, $variant] = $this->deliveredOrder(qty: 2);
        $r = $this->svc->create($order, ['type' => 'return', 'reason_code' => 'damaged', 'resolution' => 'no_refund'],
            [['order_item_id' => $this->line($order)->id, 'qty' => 2]], 2026, $this->actor('sales'));
        $this->svc->approve($r, $this->actor('sales_supervisor'));
        $this->svc->receive($r, $this->actor('warehouse'));
        $this->svc->inspect($r, [$r->items->first()->id => ['inspection_result' => 'sellable', 'inventory_route' => 'restock']], $this->actor('warehouse'));

        $this->assertEquals(1, InventoryMovement::where('type', 'return_in')->where('variant_id', $variant->id)->count());
    }

    public function test_over_return_is_prevented(): void
    {
        [$order] = $this->deliveredOrder(qty: 2);
        $r = $this->svc->create($order, ['type' => 'return', 'reason_code' => 'other', 'resolution' => 'no_refund'],
            [['order_item_id' => $this->line($order)->id, 'qty' => 2]], 2026, $this->actor('sales'));
        $this->svc->approve($r, $this->actor('sales_supervisor'));
        $this->svc->receive($r, $this->actor('warehouse'));
        $this->svc->inspect($r, [$r->items->first()->id => ['inspection_result' => 'sellable', 'inventory_route' => 'restock']], $this->actor('warehouse'));
        $this->svc->complete($r, $this->actor('finance'));

        $this->expectException(ValidationException::class);
        $this->svc->create($order->fresh(), ['type' => 'return', 'reason_code' => 'other', 'resolution' => 'no_refund'],
            [['order_item_id' => $this->line($order->fresh())->id, 'qty' => 1]], 2026, $this->actor('sales'));
    }

    public function test_return_with_refund_refunds_payment(): void
    {
        [$order] = $this->deliveredOrder(qty: 2, price: 100);
        $method = PaymentMethod::where('code', 'cod')->first();
        $payment = Payment::create([
            'number' => 'PAY-T-1', 'order_id' => $order->id, 'branch_id' => $order->branch_id,
            'payment_method_id' => $method->id, 'amount' => 200, 'status' => 'paid',
            'refunded_amount' => 0, 'provider_reference' => 'REF-1',
        ]);

        $r = $this->svc->create($order, ['type' => 'return', 'reason_code' => 'damaged', 'resolution' => 'refund'],
            [['order_item_id' => $this->line($order)->id, 'qty' => 2]], 2026, $this->actor('sales'));
        $this->svc->approve($r, $this->actor('sales_supervisor'));
        $this->svc->receive($r, $this->actor('warehouse'));
        $this->svc->inspect($r, [$r->items->first()->id => ['inspection_result' => 'sellable', 'inventory_route' => 'restock']], $this->actor('warehouse'));
        $this->svc->complete($r, $this->actor('finance'));

        $this->assertEquals(200.0, (float) $payment->fresh()->refunded_amount);
        $this->assertEquals($payment->id, $r->fresh()->refund_payment_id);
    }

    public function test_exchange_creates_linked_shipment_and_issues_replacement(): void
    {
        [$order] = $this->deliveredOrder(qty: 1);
        $replacement = $this->variant(price: 120, cost: 70); // بديل متوفّر

        $r = $this->svc->create($order, ['type' => 'exchange', 'reason_code' => 'wrong_item', 'resolution' => 'replacement'],
            [['order_item_id' => $this->line($order)->id, 'qty' => 1, 'exchange_variant_id' => $replacement->id, 'exchange_qty' => 1, 'price_difference' => 20]], 2026, $this->actor('sales'));
        $this->svc->approve($r, $this->actor('sales_supervisor'));
        $this->svc->receive($r, $this->actor('warehouse'));
        $this->svc->inspect($r, [$r->items->first()->id => ['inspection_result' => 'sellable', 'inventory_route' => 'restock']], $this->actor('warehouse'));
        $this->svc->complete($r, $this->actor('finance'));

        $this->assertEquals('exchanged', $order->fresh()->status);
        $this->assertEquals(49.0, (float) $this->stock($replacement)->on_hand);      // صُرف البديل (50−1)
        $linked = Shipment::where('kind', 'exchange_delivery')->where('order_id', $order->id)->first();
        $this->assertNotNull($linked);
        $this->assertEquals($linked->id, $r->fresh()->linked_shipment_id);
    }

    public function test_linked_return_shipment_does_not_modify_original(): void
    {
        [$order] = $this->deliveredOrder(qty: 2);
        // شحنة أصلية مستقلّة.
        $original = Shipment::create([
            'number' => 'SHP-ORIG-1', 'order_id' => $order->id, 'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id, 'status' => 'delivered', 'kind' => 'outbound',
            'recipient_name' => 'x', 'recipient_phone' => '0500000000',
        ]);

        $r = $this->svc->create($order, ['type' => 'return', 'reason_code' => 'customer_refused', 'resolution' => 'no_refund'],
            [['order_item_id' => $this->line($order)->id, 'qty' => 2]], 2026, $this->actor('sales'));
        $this->svc->approve($r, $this->actor('sales_supervisor'));
        $this->svc->receive($r, $this->actor('warehouse'), ['carrier_name' => 'Opost']);

        $linked = $r->fresh()->linkedShipment;
        $this->assertEquals('return_pickup', $linked->kind);
        $this->assertNotEquals($original->id, $linked->id);
        $this->assertEquals('delivered', $original->fresh()->status);   // الأصل لم يُمسّ
        $this->assertEquals(2, Shipment::where('order_id', $order->id)->count());
    }

    // ---- التفويض والحالات ----

    public function test_stage_authorization_via_api(): void
    {
        [$order] = $this->deliveredOrder(qty: 2);
        $r = $this->svc->create($order, ['type' => 'return', 'reason_code' => 'damaged', 'resolution' => 'no_refund'],
            [['order_item_id' => $this->line($order)->id, 'qty' => 2]], 2026, $this->actor('sales'));

        Sanctum::actingAs($this->actor('sales')); // لا يملك approve
        $this->postJson("/api/v1/rma/{$r->uuid}/approve")->assertForbidden();

        Sanctum::actingAs($this->actor('sales_supervisor'));
        $this->postJson("/api/v1/rma/{$r->uuid}/approve")->assertOk();

        Sanctum::actingAs($this->actor('warehouse'));
        $this->postJson("/api/v1/rma/{$r->uuid}/receive")->assertOk();
        $this->postJson("/api/v1/rma/{$r->uuid}/inspect", ['inspections' => [
            ['item_id' => $r->items->first()->id, 'inspection_result' => 'sellable', 'inventory_route' => 'restock'],
        ]])->assertOk();

        Sanctum::actingAs($this->actor('warehouse')); // لا يملك finalize
        $this->postJson("/api/v1/rma/{$r->uuid}/complete")->assertForbidden();
        Sanctum::actingAs($this->actor('finance'));
        $this->postJson("/api/v1/rma/{$r->uuid}/complete")->assertOk();
    }

    public function test_cannot_complete_before_inspection(): void
    {
        [$order] = $this->deliveredOrder(qty: 2);
        $r = $this->svc->create($order, ['type' => 'return', 'reason_code' => 'damaged', 'resolution' => 'no_refund'],
            [['order_item_id' => $this->line($order)->id, 'qty' => 2]], 2026, $this->actor('sales'));
        $this->svc->approve($r, $this->actor('sales_supervisor'));

        $this->expectException(ValidationException::class);
        $this->svc->complete($r, $this->actor('finance')); // ما زال approved
    }

    public function test_reject_stops_workflow(): void
    {
        [$order] = $this->deliveredOrder(qty: 2);
        $r = $this->svc->create($order, ['type' => 'return', 'reason_code' => 'damaged', 'resolution' => 'no_refund'],
            [['order_item_id' => $this->line($order)->id, 'qty' => 2]], 2026, $this->actor('sales'));
        $this->svc->reject($r, 'خارج نافذة الإرجاع', $this->actor('sales_supervisor'));

        $this->assertEquals('rejected', $r->fresh()->status);
        $this->assertEquals('خارج نافذة الإرجاع', $r->fresh()->reject_reason);
    }

    public function test_timeline_and_status_history_recorded(): void
    {
        [$order] = $this->deliveredOrder(qty: 2);
        $r = $this->svc->create($order, ['type' => 'return', 'reason_code' => 'damaged', 'resolution' => 'no_refund'],
            [['order_item_id' => $this->line($order)->id, 'qty' => 2]], 2026, $this->actor('sales'));
        $this->svc->approve($r, $this->actor('sales_supervisor'));

        $this->assertEquals(2, $r->events()->count()); // created + approved
        $this->assertEquals('supervisor', $r->events->firstWhere('to_status', 'approved')->stage);
    }

    // ---- الواجهة ----

    public function test_admin_pages_render(): void
    {
        $finance = $this->actor('finance');
        $finance->update(['email_verified_at' => now()]);
        [$order] = $this->deliveredOrder(qty: 2);
        $r = $this->svc->create($order, ['type' => 'return', 'reason_code' => 'damaged', 'resolution' => 'refund'],
            [['order_item_id' => $this->line($order)->id, 'qty' => 2]], 2026, $this->actor('sales'));

        $this->actingAs($finance)->get('/admin/returns')->assertOk()->assertSee(__('returns.rma'));
        $this->actingAs($finance)->get("/admin/returns/{$r->uuid}")->assertOk()->assertSee($r->number);
    }
}
