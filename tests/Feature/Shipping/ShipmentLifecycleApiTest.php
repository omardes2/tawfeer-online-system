<?php

namespace Tests\Feature\Shipping;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShipmentLifecycleApiTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
        $this->variant = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($this->variant, $this->warehouse, 100, 10);
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

    /** ينشئ طلبًا ويقوده حتى shipped، ويعيد uuid الطلب. */
    private function shippedOrder(float $qty = 10, float $price = 50): string
    {
        $id = $this->postJson('/api/v1/sales/orders', [
            'warehouse' => $this->warehouse->uuid,
            'customer_name' => 'عميل', 'customer_phone' => '0100000000',
            'shipping_address' => 'الرياض',
            'items' => [['variant' => $this->variant->uuid, 'qty' => $qty, 'unit_price' => $price]],
        ])->assertCreated()->json('data.id');

        foreach (['confirm', 'reserve', 'prepare', 'ready', 'ship'] as $step) {
            $this->postJson("/api/v1/sales/orders/{$id}/{$step}")->assertOk();
        }

        return $id;
    }

    public function test_cannot_create_shipment_before_order_shipped(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->postJson('/api/v1/sales/orders', [
            'warehouse' => $this->warehouse->uuid,
            'customer_name' => 'عميل', 'customer_phone' => '01',
            'items' => [['variant' => $this->variant->uuid, 'qty' => 1, 'unit_price' => 5]],
        ])->json('data.id');

        $this->postJson('/api/v1/shipping/shipments', ['order' => $orderId])->assertUnprocessable();
    }

    public function test_create_shipment_snapshots_customer_and_defaults_cost_pending(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->shippedOrder();

        $res = $this->postJson('/api/v1/shipping/shipments', [
            'order' => $orderId,
            'carrier_name' => 'مندوب',
        ])->assertCreated();

        $this->assertStringStartsWith('SHP-', $res->json('data.number'));
        $res->assertJsonPath('data.status', 'not_shipped');
        $res->assertJsonPath('data.recipient.name', 'عميل'); // لقطة من الطلب
        $res->assertJsonPath('data.cost_source', 'pending');  // لا مزوّد ولا تجاوز
        $res->assertJsonPath('data.shipping_cost', '0.00');
    }

    public function test_manual_cost_override_requires_permission_and_snapshots_to_order(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->shippedOrder(10, 50); // إجمالي الطلب 500

        $res = $this->postJson('/api/v1/shipping/shipments', [
            'order' => $orderId, 'shipping_cost' => 30,
        ])->assertCreated();
        $res->assertJsonPath('data.cost_source', 'manual');
        $res->assertJsonPath('data.shipping_cost', '30.00');

        // لقطة على الطلب: الإجمالي 500 + 30 شحن = 530.
        $this->getJson("/api/v1/sales/orders/{$orderId}")->assertJsonPath('data.total', '530.00');
    }

    public function test_warehouse_role_cannot_override_cost(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->shippedOrder();

        Sanctum::actingAs($this->withRole('warehouse')); // لا يملك shipping.override_cost
        $this->postJson('/api/v1/shipping/shipments', [
            'order' => $orderId, 'shipping_cost' => 30,
        ])->assertForbidden();
    }

    public function test_full_delivery_flow_syncs_order_status(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->shippedOrder();
        $shipmentId = $this->postJson('/api/v1/shipping/shipments', ['order' => $orderId])->json('data.id');

        $this->postJson("/api/v1/shipping/shipments/{$shipmentId}/dispatch")->assertOk()->assertJsonPath('data.status', 'in_transit');
        $this->postJson("/api/v1/shipping/shipments/{$shipmentId}/out-for-delivery")->assertOk()->assertJsonPath('data.status', 'out_for_delivery');
        $this->assertEquals('out_for_delivery', Order::where('uuid', $orderId)->value('status'));

        $this->postJson("/api/v1/shipping/shipments/{$shipmentId}/deliver")->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertEquals('delivered', Order::where('uuid', $orderId)->value('status'));
    }

    public function test_fail_syncs_order_delivery_failed(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->shippedOrder();
        $shipmentId = $this->postJson('/api/v1/shipping/shipments', ['order' => $orderId])->json('data.id');
        $this->postJson("/api/v1/shipping/shipments/{$shipmentId}/dispatch");

        $this->postJson("/api/v1/shipping/shipments/{$shipmentId}/fail", ['reason' => 'عنوان خاطئ'])
            ->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertEquals('delivery_failed', Order::where('uuid', $orderId)->value('status'));
    }

    public function test_illegal_shipment_transition_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->shippedOrder();
        $shipmentId = $this->postJson('/api/v1/shipping/shipments', ['order' => $orderId])->json('data.id');

        // تسليم قبل الإرسال غير مسموح.
        $this->postJson("/api/v1/shipping/shipments/{$shipmentId}/deliver")->assertUnprocessable();
    }

    public function test_fail_requires_reason(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->shippedOrder();
        $shipmentId = $this->postJson('/api/v1/shipping/shipments', ['order' => $orderId])->json('data.id');
        $this->postJson("/api/v1/shipping/shipments/{$shipmentId}/dispatch");

        $this->postJson("/api/v1/shipping/shipments/{$shipmentId}/fail", [])->assertUnprocessable();
    }

    public function test_only_one_shipment_per_order(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->shippedOrder();
        $this->postJson('/api/v1/shipping/shipments', ['order' => $orderId])->assertCreated();

        // شحنة ثانية لنفس الطلب مرفوضة (تمنع ازدواج لقطة التكلفة).
        $this->postJson('/api/v1/shipping/shipments', ['order' => $orderId])->assertUnprocessable();
    }

    public function test_index_lists_shipments(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->shippedOrder();
        $this->postJson('/api/v1/shipping/shipments', ['order' => $orderId])->assertCreated();

        $res = $this->getJson('/api/v1/shipping/shipments')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertArrayNotHasKey('events', $res->json('data.0')); // غير مُحمّل في القائمة
    }
}
