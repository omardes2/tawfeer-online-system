<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryLedger;
use App\Modules\Inventory\Models\InventoryStock;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryOperationsApiTest extends TestCase
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

    private function withRole(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    private function variant()
    {
        return Product::factory()->create()->defaultVariant;
    }

    public function test_unauthenticated_rejected(): void
    {
        $this->postJson('/api/v1/inventory/receive', [])->assertUnauthorized();
    }

    public function test_sales_cannot_receive_but_can_view_stocks(): void
    {
        Sanctum::actingAs($this->withRole('sales'));
        $this->getJson('/api/v1/inventory/stocks')->assertOk();
        // حمولة صحيحة كي يتخطّى التحقّق ويصل لفحص الصلاحية (403 لا 422).
        $v = $this->variant();
        $this->postJson('/api/v1/inventory/receive', [
            'variant' => $v->uuid, 'warehouse' => $this->warehouse->uuid, 'qty' => 5, 'unit_cost' => 3,
        ])->assertForbidden();
    }

    public function test_receive_increases_on_hand_and_computes_wac(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->variant();

        $this->postJson('/api/v1/inventory/receive', ['variant' => $v->uuid, 'warehouse' => $this->warehouse->uuid, 'qty' => 10, 'unit_cost' => 5])->assertCreated();
        $this->postJson('/api/v1/inventory/receive', ['variant' => $v->uuid, 'warehouse' => $this->warehouse->uuid, 'qty' => 10, 'unit_cost' => 7])->assertCreated();

        $stock = InventoryStock::where('variant_id', $v->id)->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(20, $stock->on_hand);
        $this->assertEquals(6, (float) $stock->average_cost); // (50+70)/20
        $this->assertSame(2, InventoryLedger::count());
    }

    public function test_issue_decreases_on_hand(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->variant();
        $this->postJson('/api/v1/inventory/receive', ['variant' => $v->uuid, 'warehouse' => $this->warehouse->uuid, 'qty' => 10, 'unit_cost' => 5])->assertCreated();

        $this->postJson('/api/v1/inventory/issue', ['variant' => $v->uuid, 'warehouse' => $this->warehouse->uuid, 'qty' => 4])->assertCreated();

        $stock = InventoryStock::where('variant_id', $v->id)->first();
        $this->assertEquals(6, $stock->on_hand);
    }

    public function test_negative_stock_prevented_by_default(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->variant();

        $this->postJson('/api/v1/inventory/issue', ['variant' => $v->uuid, 'warehouse' => $this->warehouse->uuid, 'qty' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('qty');
    }

    public function test_negative_allowed_when_warehouse_permits(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->variant();
        $wh = Warehouse::factory()->create(['allow_negative' => true, 'branch_id' => Branch::default()->id]);

        $this->postJson('/api/v1/inventory/issue', ['variant' => $v->uuid, 'warehouse' => $wh->uuid, 'qty' => 5])->assertCreated();
        $this->assertEquals(-5, InventoryStock::where('variant_id', $v->id)->where('warehouse_id', $wh->id)->first()->on_hand);
    }

    public function test_transfer_moves_stock_between_warehouses(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->variant();
        $dest = Warehouse::factory()->create(['branch_id' => Branch::default()->id]);
        $this->postJson('/api/v1/inventory/receive', ['variant' => $v->uuid, 'warehouse' => $this->warehouse->uuid, 'qty' => 20, 'unit_cost' => 8])->assertCreated();

        $this->postJson('/api/v1/inventory/transfer', ['variant' => $v->uuid, 'from_warehouse' => $this->warehouse->uuid, 'to_warehouse' => $dest->uuid, 'qty' => 8])->assertCreated();

        $this->assertEquals(12, InventoryStock::where('variant_id', $v->id)->where('warehouse_id', $this->warehouse->id)->first()->on_hand);
        $destStock = InventoryStock::where('variant_id', $v->id)->where('warehouse_id', $dest->id)->first();
        $this->assertEquals(8, $destStock->on_hand);
        $this->assertEquals(8, (float) $destStock->average_cost); // WAC carried
    }

    public function test_transfer_to_same_warehouse_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->variant();
        $this->postJson('/api/v1/inventory/transfer', ['variant' => $v->uuid, 'from_warehouse' => $this->warehouse->uuid, 'to_warehouse' => $this->warehouse->uuid, 'qty' => 1])
            ->assertUnprocessable();
    }

    public function test_cost_fields_hidden_from_sales_on_stocks(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->variant();
        $this->postJson('/api/v1/inventory/receive', ['variant' => $v->uuid, 'warehouse' => $this->warehouse->uuid, 'qty' => 10, 'unit_cost' => 5])->assertCreated();

        Sanctum::actingAs($this->withRole('sales'));
        $this->getJson('/api/v1/inventory/stocks')->assertOk()->assertJsonMissing(['average_cost' => 5]);
    }

    public function test_movements_and_ledger_are_recorded_and_viewable(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->variant();
        $this->postJson('/api/v1/inventory/receive', ['variant' => $v->uuid, 'warehouse' => $this->warehouse->uuid, 'qty' => 10, 'unit_cost' => 5])->assertCreated();

        $this->getJson('/api/v1/inventory/movements')->assertOk()->assertJsonPath('data.0.type', 'purchase_in');
        $this->getJson('/api/v1/inventory/ledger')->assertOk()->assertJsonPath('data.0.movement_type', 'purchase_in');
    }
}
