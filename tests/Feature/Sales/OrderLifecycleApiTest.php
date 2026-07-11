<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderLifecycleApiTest extends TestCase
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

    private function createOrder(float $qty = 30, float $price = 25): string
    {
        return $this->postJson('/api/v1/sales/orders', [
            'warehouse' => $this->warehouse->uuid,
            'customer_name' => 'عميل',
            'customer_phone' => '0100000000',
            'items' => [['variant' => $this->variant->uuid, 'qty' => $qty, 'unit_price' => $price]],
        ])->assertCreated()->json('data.id');
    }

    private function stock(): InventoryStock
    {
        return InventoryStock::where('variant_id', $this->variant->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
    }

    public function test_create_generates_number_and_totals(): void
    {
        Sanctum::actingAs($this->admin());

        $res = $this->postJson('/api/v1/sales/orders', [
            'warehouse' => $this->warehouse->uuid,
            'customer_name' => 'عميل',
            'customer_phone' => '0100000000',
            'items' => [['variant' => $this->variant->uuid, 'qty' => 10, 'unit_price' => 5, 'discount' => 5]],
        ])->assertCreated();

        $this->assertStringStartsWith('SO-', $res->json('data.number'));
        $res->assertJsonPath('data.status', 'draft');
        $res->assertJsonPath('data.total', '45.00');
        // سجلّ حالة الإنشاء.
        $this->assertEquals('draft', $res->json('data.history.0.to_status'));
    }

    public function test_full_lifecycle_moves_stock_per_adr_009(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createOrder(30, 25);

        $this->postJson("/api/v1/sales/orders/{$id}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->postJson("/api/v1/sales/orders/{$id}/reserve")->assertOk()->assertJsonPath('data.status', 'stock_reserved');
        $s = $this->stock();
        $this->assertEquals(100, (float) $s->on_hand);   // لم يُمسّ
        $this->assertEquals(30, (float) $s->reserved);   // محجوز

        $this->postJson("/api/v1/sales/orders/{$id}/prepare")->assertOk()->assertJsonPath('data.status', 'preparing');
        $this->postJson("/api/v1/sales/orders/{$id}/ready")->assertOk()->assertJsonPath('data.status', 'ready_to_ship');

        $this->postJson("/api/v1/sales/orders/{$id}/ship")->assertOk()->assertJsonPath('data.status', 'shipped');
        $s = $this->stock();
        $this->assertEquals(70, (float) $s->on_hand);    // خُصم نهائيًا
        $this->assertEquals(0, (float) $s->reserved);    // استُهلك الحجز

        // حركة بيع مكتوبة + الحجز consumed.
        $this->assertDatabaseHas('inventory_movements', ['variant_id' => $this->variant->id, 'type' => 'sale_out']);
        $this->assertEquals('consumed', StockReservation::where('order_id', $this->orderId($id))->first()->status);

        $this->postJson("/api/v1/sales/orders/{$id}/deliver")->assertOk()->assertJsonPath('data.status', 'delivered');
    }

    public function test_cancel_before_ship_releases_reservation(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createOrder(20, 10);
        $this->postJson("/api/v1/sales/orders/{$id}/confirm");
        $this->postJson("/api/v1/sales/orders/{$id}/reserve");
        $this->assertEquals(20, (float) $this->stock()->reserved);

        $this->postJson("/api/v1/sales/orders/{$id}/cancel", ['reason' => 'طلب العميل'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertEquals(0, (float) $this->stock()->reserved); // حُرّر
        $this->assertEquals(100, (float) $this->stock()->on_hand);
        $this->assertEquals('released', StockReservation::where('order_id', $this->orderId($id))->first()->status);
    }

    public function test_cancel_requires_reason(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createOrder();

        $this->postJson("/api/v1/sales/orders/{$id}/cancel", [])->assertUnprocessable();
    }

    public function test_illegal_transition_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createOrder();

        // شحن قبل الحجز غير مسموح.
        $this->postJson("/api/v1/sales/orders/{$id}/ship")->assertUnprocessable();
        // حجز قبل التأكيد غير مسموح.
        $this->postJson("/api/v1/sales/orders/{$id}/reserve")->assertUnprocessable();
    }

    public function test_reserving_beyond_available_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createOrder(500, 10); // أكثر من المتاح (100)
        $this->postJson("/api/v1/sales/orders/{$id}/confirm");

        $this->postJson("/api/v1/sales/orders/{$id}/reserve")->assertUnprocessable();
        $this->assertEquals(0, (float) $this->stock()->reserved);
    }

    private function orderId(string $uuid): int
    {
        return Order::where('uuid', $uuid)->value('id');
    }
}
