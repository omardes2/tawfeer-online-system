<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Purchasing\Models\Supplier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierReturnApiTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
        $this->supplier = Supplier::factory()->create();
        $this->variant = Product::factory()->create()->defaultVariant;
        // رصيد ابتدائي عبر المحرّك.
        app(InventoryService::class)->receive($this->variant, $this->warehouse, 100, 10);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    private function createReturn(float $qty = 10): string
    {
        return $this->postJson('/api/v1/purchasing/returns', [
            'supplier' => $this->supplier->uuid,
            'warehouse' => $this->warehouse->uuid,
            'reason' => 'تالف',
            'items' => [['variant' => $this->variant->uuid, 'qty' => $qty, 'unit_cost' => 10]],
        ])->assertCreated()->json('data.id');
    }

    public function test_approve_then_post_decreases_stock_through_engine(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createReturn(10);

        $this->postJson("/api/v1/purchasing/returns/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/purchasing/returns/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $stock = InventoryStock::where('variant_id', $this->variant->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(90, (float) $stock->on_hand);

        $this->assertDatabaseHas('inventory_movements', [
            'variant_id' => $this->variant->id,
            'type' => 'purchase_return_out',
        ]);
    }

    public function test_cannot_post_before_approval(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createReturn(5);

        $this->postJson("/api/v1/purchasing/returns/{$id}/post")->assertUnprocessable();
    }

    public function test_cannot_return_more_than_available(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->createReturn(500); // أكثر من المتاح (100)
        $this->postJson("/api/v1/purchasing/returns/{$id}/approve")->assertOk();

        $this->postJson("/api/v1/purchasing/returns/{$id}/post")->assertUnprocessable();
        // لم يتغيّر المخزون.
        $stock = InventoryStock::where('variant_id', $this->variant->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(100, (float) $stock->on_hand);
    }

    public function test_warehouse_role_cannot_post_return(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('warehouse');
        Sanctum::actingAs($u);
        $id = $this->createReturn(5);

        // المستودع لا يملك returns.approve ولا returns.post.
        $this->postJson("/api/v1/purchasing/returns/{$id}/approve")->assertForbidden();
    }
}
