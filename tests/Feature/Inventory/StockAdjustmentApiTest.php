<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockAdjustmentApiTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    private function stockedVariant(float $qty = 100)
    {
        $v = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($v, $this->warehouse, $qty, 10);

        return $v;
    }

    public function test_create_adjustment_computes_diff_and_generates_number(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->stockedVariant(100);

        $res = $this->postJson('/api/v1/inventory/adjustments', [
            'warehouse' => $this->warehouse->uuid,
            'type' => 'recount',
            'items' => [['variant' => $v->uuid, 'qty_counted' => 90]],
        ])->assertCreated();

        $res->assertJsonPath('data.status', 'draft');
        $this->assertStringStartsWith('ADJ-', $res->json('data.number'));
        $this->assertEquals(-10, $res->json('data.items.0.qty_diff'));
    }

    public function test_approve_then_post_applies_to_stock(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->stockedVariant(100);
        $id = $this->postJson('/api/v1/inventory/adjustments', [
            'warehouse' => $this->warehouse->uuid,
            'items' => [['variant' => $v->uuid, 'qty_counted' => 90]],
        ])->json('data.id');

        $this->postJson("/api/v1/inventory/adjustments/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/inventory/adjustments/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $this->assertEquals(90, InventoryStock::where('variant_id', $v->id)->first()->on_hand);
    }

    public function test_cannot_post_before_approval(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->stockedVariant();
        $id = $this->postJson('/api/v1/inventory/adjustments', [
            'warehouse' => $this->warehouse->uuid,
            'items' => [['variant' => $v->uuid, 'qty_counted' => 5]],
        ])->json('data.id');

        $this->postJson("/api/v1/inventory/adjustments/{$id}/post")->assertUnprocessable();
    }

    public function test_cannot_delete_posted_adjustment(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->stockedVariant();
        $id = $this->postJson('/api/v1/inventory/adjustments', [
            'warehouse' => $this->warehouse->uuid,
            'items' => [['variant' => $v->uuid, 'qty_counted' => 5]],
        ])->json('data.id');
        $this->postJson("/api/v1/inventory/adjustments/{$id}/approve");
        $this->postJson("/api/v1/inventory/adjustments/{$id}/post");

        $this->deleteJson("/api/v1/inventory/adjustments/{$id}")->assertStatus(422);
    }

    public function test_warehouse_role_cannot_approve(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('warehouse');
        Sanctum::actingAs($u);
        $v = $this->stockedVariant();
        $id = $this->postJson('/api/v1/inventory/adjustments', [
            'warehouse' => $this->warehouse->uuid,
            'items' => [['variant' => $v->uuid, 'qty_counted' => 5]],
        ])->json('data.id');

        $this->postJson("/api/v1/inventory/adjustments/{$id}/approve")->assertForbidden();
    }
}
