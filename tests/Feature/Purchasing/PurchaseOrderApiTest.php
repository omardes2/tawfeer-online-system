<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Purchasing\Models\Supplier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseOrderApiTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
        $this->supplier = Supplier::factory()->create();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    private function newVariant()
    {
        return Product::factory()->create()->defaultVariant;
    }

    private function createOrder(): string
    {
        return $this->postJson('/api/v1/purchasing/orders', [
            'supplier' => $this->supplier->uuid,
            'warehouse' => $this->warehouse->uuid,
            'items' => [
                ['variant' => $this->newVariant()->uuid, 'qty_ordered' => 100, 'unit_cost' => 10],
            ],
        ])->assertCreated()->json('data.id');
    }

    public function test_create_order_generates_number_and_totals(): void
    {
        Sanctum::actingAs($this->admin());

        $res = $this->postJson('/api/v1/purchasing/orders', [
            'supplier' => $this->supplier->uuid,
            'warehouse' => $this->warehouse->uuid,
            'items' => [
                ['variant' => $this->newVariant()->uuid, 'qty_ordered' => 10, 'unit_cost' => 5, 'discount' => 5],
            ],
        ])->assertCreated();

        $this->assertStringStartsWith('PO-', $res->json('data.number'));
        $res->assertJsonPath('data.status', 'draft');
        $res->assertJsonPath('data.total', '45.00');
    }

    public function test_full_lifecycle_submit_approve(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createOrder();

        $this->postJson("/api/v1/purchasing/orders/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $this->postJson("/api/v1/purchasing/orders/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_cannot_update_after_approval(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createOrder();
        $this->postJson("/api/v1/purchasing/orders/{$id}/approve");

        $this->putJson("/api/v1/purchasing/orders/{$id}", ['notes' => 'late'])->assertUnprocessable();
    }

    public function test_cannot_approve_twice_when_already_received_path(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createOrder();
        $this->postJson("/api/v1/purchasing/orders/{$id}/approve")->assertOk();
        // إعادة الاعتماد على أمر معتمد غير مسموحة
        $this->postJson("/api/v1/purchasing/orders/{$id}/approve")->assertUnprocessable();
    }

    public function test_warehouse_role_cannot_approve(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('warehouse');
        Sanctum::actingAs($u);
        $id = $this->createOrder();

        $this->postJson("/api/v1/purchasing/orders/{$id}/approve")->assertForbidden();
    }

    public function test_cost_fields_hidden_without_permission(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('warehouse'); // ليس لديه pricing.view_cost
        Sanctum::actingAs($u);
        $id = $this->createOrder();

        $res = $this->getJson("/api/v1/purchasing/orders/{$id}")->assertOk();
        $this->assertArrayNotHasKey('total', $res->json('data'));
        $this->assertArrayNotHasKey('unit_cost', $res->json('data.items.0'));
    }
}
