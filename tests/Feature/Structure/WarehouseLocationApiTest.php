<?php

namespace Tests\Feature\Structure;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Foundation\Models\WarehouseLocation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WarehouseLocationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Sanctum::actingAs(User::where('email', 'admin@tawfeer.online')->first());
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::factory()->create(['branch_id' => Branch::default()->id]);
    }

    public function test_can_create_location_under_warehouse(): void
    {
        $warehouse = $this->warehouse();

        $this->postJson("/api/v1/warehouses/{$warehouse->uuid}/locations", [
            'code' => 'A-01',
            'name' => 'رفّ 1',
            'type' => 'shelf',
        ])->assertCreated()->assertJsonPath('data.code', 'A-01');

        $this->assertDatabaseHas('warehouse_locations', ['warehouse_id' => $warehouse->id, 'code' => 'A-01']);
    }

    public function test_location_code_unique_per_warehouse(): void
    {
        $warehouse = $this->warehouse();
        WarehouseLocation::factory()->create(['warehouse_id' => $warehouse->id, 'code' => 'DUP']);

        $this->postJson("/api/v1/warehouses/{$warehouse->uuid}/locations", ['code' => 'DUP'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_can_nest_locations_with_parent(): void
    {
        $warehouse = $this->warehouse();
        $parent = WarehouseLocation::factory()->create(['warehouse_id' => $warehouse->id]);

        $this->postJson("/api/v1/warehouses/{$warehouse->uuid}/locations", [
            'code' => 'CHILD-1',
            'parent_id' => $parent->id,
        ])->assertCreated()->assertJsonPath('data.parent_id', $parent->id);
    }

    public function test_parent_must_belong_to_same_warehouse(): void
    {
        $warehouse = $this->warehouse();
        $foreignParent = WarehouseLocation::factory()->create(); // مستودع آخر

        $this->postJson("/api/v1/warehouses/{$warehouse->uuid}/locations", [
            'code' => 'BAD',
            'parent_id' => $foreignParent->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_scoped_binding_rejects_location_from_other_warehouse(): void
    {
        $warehouseA = $this->warehouse();
        $warehouseB = $this->warehouse();
        $locationB = WarehouseLocation::factory()->create(['warehouse_id' => $warehouseB->id]);

        // الموقع يخص المستودع B، فلا يُحلّ تحت المستودع A (ربط مُنطاق).
        $this->getJson("/api/v1/warehouses/{$warehouseA->uuid}/locations/{$locationB->id}")
            ->assertNotFound();
    }

    public function test_can_update_and_delete_location(): void
    {
        $warehouse = $this->warehouse();
        $location = WarehouseLocation::factory()->create(['warehouse_id' => $warehouse->id]);

        $this->patchJson("/api/v1/warehouses/{$warehouse->uuid}/locations/{$location->id}", ['name' => 'محدّث'])
            ->assertOk()->assertJsonPath('data.name', 'محدّث');

        $this->deleteJson("/api/v1/warehouses/{$warehouse->uuid}/locations/{$location->id}")->assertOk();
        $this->assertDatabaseMissing('warehouse_locations', ['id' => $location->id]);
    }
}
