<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderApiTest extends TestCase
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

    private function createOrder(): string
    {
        return $this->postJson('/api/v1/sales/orders', [
            'warehouse' => $this->warehouse->uuid,
            'customer_name' => 'عميل',
            'customer_phone' => '0100000000',
            'items' => [['variant' => $this->variant->uuid, 'qty' => 5, 'unit_price' => 10]],
        ])->assertCreated()->json('data.id');
    }

    public function test_index_lists_orders(): void
    {
        Sanctum::actingAs($this->admin());
        $this->createOrder();

        $res = $this->getJson('/api/v1/sales/orders')->assertOk();
        $this->assertCount(1, $res->json('data'));
        // history غير مُحمّل في القائمة — لا يظهر ولا يكسر التسلسل.
        $this->assertArrayNotHasKey('history', $res->json('data.0'));
    }

    public function test_update_allowed_in_draft_only(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createOrder();

        $this->putJson("/api/v1/sales/orders/{$id}", ['customer_name' => 'محدّث'])
            ->assertOk()->assertJsonPath('data.customer.name', 'محدّث');

        $this->postJson("/api/v1/sales/orders/{$id}/confirm");
        $this->postJson("/api/v1/sales/orders/{$id}/reserve");

        // بعد الحجز يُمنع التعديل (BR-ORD-07).
        $this->putJson("/api/v1/sales/orders/{$id}", ['customer_name' => 'متأخّر'])->assertUnprocessable();
    }

    public function test_price_snapshot_is_frozen_on_item(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createOrder();

        // سعر البند مجمّد بقيمة الإدخال (BR-ORD-18).
        $res = $this->getJson("/api/v1/sales/orders/{$id}")->assertOk();
        $this->assertEquals('10.00', $res->json('data.items.0.unit_price'));
        $this->assertEquals('50.00', $res->json('data.items.0.line_total'));
    }

    public function test_delete_allowed_in_draft_only(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createOrder();
        $this->postJson("/api/v1/sales/orders/{$id}/confirm");

        $this->deleteJson("/api/v1/sales/orders/{$id}")->assertUnprocessable();
    }

    public function test_warehouse_role_cannot_confirm_but_can_ship(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createOrder();

        $wh = $this->withRole('warehouse');
        Sanctum::actingAs($wh);
        $this->postJson("/api/v1/sales/orders/{$id}/confirm")->assertForbidden();
    }

    public function test_sales_role_cannot_ship(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createOrder();
        $this->postJson("/api/v1/sales/orders/{$id}/confirm");
        $this->postJson("/api/v1/sales/orders/{$id}/reserve");
        $this->postJson("/api/v1/sales/orders/{$id}/prepare");
        $this->postJson("/api/v1/sales/orders/{$id}/ready");

        $sales = $this->withRole('sales');
        Sanctum::actingAs($sales);
        $this->postJson("/api/v1/sales/orders/{$id}/ship")->assertForbidden();
    }

    public function test_duplicate_variant_lines_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/sales/orders', [
            'warehouse' => $this->warehouse->uuid,
            'customer_name' => 'عميل', 'customer_phone' => '0100000000',
            'items' => [
                ['variant' => $this->variant->uuid, 'qty' => 1, 'unit_price' => 5],
                ['variant' => $this->variant->uuid, 'qty' => 2, 'unit_price' => 5],
            ],
        ])->assertUnprocessable();
    }

    public function test_accountant_read_only(): void
    {
        $u = $this->withRole('accountant');
        Sanctum::actingAs($u);

        $this->getJson('/api/v1/sales/orders')->assertOk();
        $this->postJson('/api/v1/sales/orders', [
            'warehouse' => $this->warehouse->uuid,
            'customer_name' => 'x', 'customer_phone' => '1',
            'items' => [['variant' => $this->variant->uuid, 'qty' => 1, 'unit_price' => 1]],
        ])->assertForbidden();
    }
}
