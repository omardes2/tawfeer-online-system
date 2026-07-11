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

class StockReservationApiTest extends TestCase
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

    private function stockedVariant(float $qty = 50)
    {
        $v = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($v, $this->warehouse, $qty, 10);

        return $v;
    }

    public function test_reserve_increases_reserved_and_reduces_available(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->stockedVariant(50);

        $this->postJson('/api/v1/inventory/reservations', ['variant' => $v->uuid, 'warehouse' => $this->warehouse->uuid, 'qty' => 20])
            ->assertCreated()->assertJsonPath('data.status', 'active');

        $stock = InventoryStock::where('variant_id', $v->id)->first();
        $this->assertEquals(20, $stock->reserved);
        $this->assertEquals(30, $stock->available);
    }

    public function test_cannot_reserve_more_than_available(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->stockedVariant(10);

        $this->postJson('/api/v1/inventory/reservations', ['variant' => $v->uuid, 'warehouse' => $this->warehouse->uuid, 'qty' => 20])
            ->assertUnprocessable()->assertJsonValidationErrors('qty');
    }

    public function test_release_returns_reserved_to_available(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->stockedVariant(50);
        $res = $this->postJson('/api/v1/inventory/reservations', ['variant' => $v->uuid, 'warehouse' => $this->warehouse->uuid, 'qty' => 20])->json('data.id');

        $this->postJson("/api/v1/inventory/reservations/{$res}/release")->assertOk()->assertJsonPath('data.status', 'released');

        $stock = InventoryStock::where('variant_id', $v->id)->first();
        $this->assertEquals(0, $stock->reserved);
        $this->assertEquals(50, $stock->available);
    }

    public function test_sales_cannot_create_reservation(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('sales');
        Sanctum::actingAs($u);
        $v = $this->stockedVariant();

        $this->postJson('/api/v1/inventory/reservations', ['variant' => $v->uuid, 'warehouse' => $this->warehouse->uuid, 'qty' => 1])->assertForbidden();
    }
}
