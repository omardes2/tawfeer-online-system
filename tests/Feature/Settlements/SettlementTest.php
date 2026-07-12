<?php

namespace Tests\Feature\Settlements;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Settlements\Models\DeliverySettlement;
use App\Modules\Settlements\Services\SettlementService;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Models\ShipmentFeeComponent;
use App\Modules\Shipping\Support\DeliveryStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettlementTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private SettlementService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
        $this->svc = app(SettlementService::class);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    private function variant(float $price = 100, float $cost = 60): ProductVariant
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => $price]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => $price]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 50, $cost);

        return $variant->fresh();
    }

    /** طلب مُسلَّم بعمولة pending + شحنة مُغلقة (delivery_status=closed) ورسوم. */
    private function closedShipmentOrder(float $qty = 2, float $price = 100, float $fee = 15): array
    {
        $variant = $this->variant($price);
        $rep = $this->actor('sales');
        $os = app(OrderService::class);
        $order = $os->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'x', 'customer_phone' => '0500000000',
            'assigned_to' => $rep->id,
        ], [['variant_id' => $variant->id, 'qty' => $qty, 'unit_price' => $price, 'discount' => 0]], 2026);
        $order->items->each->update(['wholesale_cost_snapshot' => 60]);
        $os->confirm($order);
        $os->reserveStock($order->fresh());
        $os->startPreparing($order->fresh());
        $os->markReady($order->fresh());
        $os->ship($order->fresh());
        $os->deliver($order->fresh()); // عمولة pending

        $shipment = Shipment::create([
            'number' => 'SHP-CL-'.$order->id, 'order_id' => $order->id, 'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id, 'status' => 'delivered', 'delivery_status' => DeliveryStatus::CLOSED,
            'closed_at' => now(), 'kind' => 'outbound', 'recipient_name' => 'x', 'recipient_phone' => '0500000000',
        ]);
        ShipmentFeeComponent::create(['shipment_id' => $shipment->id, 'type' => 'delivery', 'amount' => $fee, 'owner' => 'provider', 'source' => 'actual']);

        return [$order->fresh(), $shipment, $rep];
    }

    public function test_reconcile_computes_totals_and_detects_variance(): void
    {
        [, $shipment] = $this->closedShipmentOrder(qty: 2, price: 100, fee: 15); // cod 200, net 185
        $s = $this->svc->create(['reported_cod_total' => 200, 'reported_fees_total' => 15, 'reported_net' => 200], $this->actor('finance'));
        $this->svc->addClosedShipments($s, [$shipment->id], $this->actor('finance'));
        $this->svc->reconcile($s, $this->actor('finance'));

        $s->refresh();
        $this->assertEquals('200.00', $s->computed_cod_total);
        $this->assertEquals('15.00', $s->computed_fees_total);
        $this->assertEquals('185.00', $s->computed_net);
        $this->assertEquals('15.00', $s->variance); // reported_net 200 − computed_net 185
    }

    public function test_post_creates_balanced_journal_entry_and_settles(): void
    {
        [$order, $shipment, $rep] = $this->closedShipmentOrder(qty: 2, price: 100, fee: 15);
        $this->assertEquals('pending', CommissionEntry::where('order_id', $order->id)->first()->state);

        $s = $this->svc->create(['reported_cod_total' => 200, 'reported_fees_total' => 15, 'reported_net' => 185], $this->actor('finance'));
        $this->svc->addClosedShipments($s, [$shipment->id], $this->actor('finance'));
        $this->svc->reconcile($s, $this->actor('finance'));
        $this->svc->post($s, $this->actor('finance'));

        $s->refresh();
        $this->assertEquals('posted', $s->status);
        $this->assertNotNull($s->accounting_entry_id);

        // القيد المحاسبي متوازن ومرتبط بالتسوية.
        $entry = JournalEntry::where('reference_type', DeliverySettlement::class)->where('reference_id', $s->id)->first();
        $this->assertNotNull($entry);
        $this->assertEquals('posted', $entry->status);
        $entry->load('lines');
        $this->assertEquals(200.0, round((float) $entry->lines->sum('debit'), 2));  // 185 نقد + 15 مصروف
        $this->assertEquals(200.0, round((float) $entry->lines->sum('credit'), 2)); // 200 COD clearing

        // تأكيد التسوية والاستحقاق.
        $this->assertNotNull($order->fresh()->settled_at);
        $this->assertEquals('eligible', CommissionEntry::where('order_id', $order->id)->first()->state);
    }

    public function test_manual_deduction_line_reflected_in_net_and_posting(): void
    {
        [, $shipment] = $this->closedShipmentOrder(qty: 2, price: 100, fee: 10);
        $s = $this->svc->create(['reported_net' => 175], $this->actor('finance'));
        $this->svc->addClosedShipments($s, [$shipment->id], $this->actor('finance'));
        // خصم عام (بديل مطالبات): 15.
        $this->svc->addLine($s, ['deduction_amount' => 15, 'note' => 'خصم تسوية'], $this->actor('finance'));
        $this->svc->reconcile($s, $this->actor('finance'));

        $s->refresh();
        $this->assertEquals('200.00', $s->computed_cod_total);
        $this->assertEquals('10.00', $s->computed_fees_total);
        $this->assertEquals('15.00', $s->computed_deductions_total);
        $this->assertEquals('175.00', $s->computed_net); // 200 − 10 − 15

        $this->svc->post($s->fresh(), $this->actor('finance'));
        $entry = JournalEntry::where('reference_id', $s->id)->where('reference_type', DeliverySettlement::class)->first();
        $entry->load('lines');
        $this->assertEquals(200.0, round((float) $entry->lines->sum('debit'), 2)); // 175 + 10 + 15
    }

    public function test_cannot_post_before_reconcile(): void
    {
        [, $shipment] = $this->closedShipmentOrder();
        $s = $this->svc->create([], $this->actor('finance'));
        $this->svc->addClosedShipments($s, [$shipment->id], $this->actor('finance'));

        $this->expectException(ValidationException::class);
        $this->svc->post($s, $this->actor('finance')); // ما زالت draft
    }

    public function test_closed_shipment_lines_are_not_duplicated(): void
    {
        [, $shipment] = $this->closedShipmentOrder();
        $s = $this->svc->create([], $this->actor('finance'));
        $this->svc->addClosedShipments($s, [$shipment->id], $this->actor('finance'));
        $this->svc->addClosedShipments($s, [$shipment->id], $this->actor('finance'));

        $this->assertEquals(1, $s->lines()->count());
    }

    public function test_authorization_on_settlement_actions(): void
    {
        [, $shipment] = $this->closedShipmentOrder();
        $s = $this->svc->create([], $this->actor('finance'));
        $this->svc->addClosedShipments($s, [$shipment->id], $this->actor('finance'));

        Sanctum::actingAs($this->actor('sales')); // لا صلاحية
        $this->getJson("/api/v1/settlements/{$s->uuid}")->assertForbidden();

        Sanctum::actingAs($this->actor('accountant')); // view + reconcile، لا post
        $this->postJson("/api/v1/settlements/{$s->uuid}/reconcile")->assertOk();
        $this->postJson("/api/v1/settlements/{$s->uuid}/post")->assertForbidden();

        Sanctum::actingAs($this->actor('finance'));
        $this->postJson("/api/v1/settlements/{$s->uuid}/post")->assertOk();
    }

    public function test_admin_pages_render(): void
    {
        $finance = $this->actor('finance');
        $finance->update(['email_verified_at' => now()]);
        [, $shipment] = $this->closedShipmentOrder();
        $s = $this->svc->create([], $finance);
        $this->svc->addClosedShipments($s, [$shipment->id], $finance);

        $this->actingAs($finance)->get('/admin/settlements')->assertOk()->assertSee(__('settlements.settlements'));
        $this->actingAs($finance)->get("/admin/settlements/{$s->uuid}")->assertOk()->assertSee($s->number);
    }
}
