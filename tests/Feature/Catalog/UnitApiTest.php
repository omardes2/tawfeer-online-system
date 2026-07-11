<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Unit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnitApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    public function test_seeded_units_are_listed(): void
    {
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/units')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'code', 'conversion_factor']], 'meta']);
    }

    public function test_admin_can_create_unit_with_unique_code(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/units', ['name' => 'دزينة', 'code' => 'DZN', 'conversion_factor' => 12])
            ->assertCreated()->assertJsonPath('data.code', 'DZN');
    }

    public function test_duplicate_code_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/units', ['name' => 'x', 'code' => 'PCS'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_unit_cannot_be_its_own_base(): void
    {
        Sanctum::actingAs($this->admin());
        $unit = Unit::factory()->create();

        $this->patchJson('/api/v1/units/'.$unit->id, ['base_unit_id' => $unit->id])
            ->assertUnprocessable()->assertJsonValidationErrors('base_unit_id');
    }

    public function test_cannot_delete_unit_referenced_as_base(): void
    {
        Sanctum::actingAs($this->admin());
        $base = Unit::factory()->create();
        Unit::factory()->create(['base_unit_id' => $base->id]);

        $this->deleteJson('/api/v1/units/'.$base->id)->assertUnprocessable();
    }

    public function test_conversion_factor_must_be_positive(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/units', ['name' => 'x', 'code' => 'NEG', 'conversion_factor' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('conversion_factor');
    }
}
