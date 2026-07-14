<?php

namespace Tests\Feature\Shipping;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Foundation\Models\Governorate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GeographyApiTest extends TestCase
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

    public function test_geography_hierarchy_is_seeded_and_readable(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/geo/governorates')->assertOk()
            ->assertJsonPath('data.0.name', fn ($v) => is_string($v));
        $gov = Governorate::where('name', 'القدس')->firstOrFail();

        $this->getJson("/api/v1/geo/cities?governorate_id={$gov->id}")->assertOk()
            ->assertJsonFragment(['name' => 'بيت لحم']);
    }

    public function test_local_city_maps_to_multiple_providers_simultaneously(): void
    {
        $city = City::where('name', 'رام الله')->firstOrFail();

        $aramex = DeliveryProvider::create(['name' => 'Aramex', 'code' => 'aramex', 'driver' => 'null']);
        $smsa = DeliveryProvider::create(['name' => 'SMSA', 'code' => 'smsa', 'driver' => 'null']);

        $city->providerMappings()->create([
            'delivery_provider_id' => $aramex->id, 'external_id' => 'ARX-JED', 'sync_status' => 'synced',
        ]);
        $city->providerMappings()->create([
            'delivery_provider_id' => $smsa->id, 'external_id' => 'SMSA-21', 'sync_status' => 'synced',
        ]);

        // نفس المدينة المحلية مُعيَّنة لمزوّدين مختلفين في آنٍ.
        $this->assertEquals(2, $city->providerMappings()->count());
        $this->assertEquals('ARX-JED', $city->providerMappings()->where('delivery_provider_id', $aramex->id)->value('external_id'));

        // تعطيل مزوّد لا يفقد الجغرافيا المحلية ولا تعيينات المزوّد الآخر.
        $aramex->update(['is_active' => false]);
        $this->assertDatabaseHas('cities', ['id' => $city->id, 'name' => 'رام الله']);
        $this->assertEquals(1, $city->providerMappings()->whereHas('provider', fn ($q) => $q->where('is_active', true))->count());
    }

    public function test_geography_requires_permission(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('affiliate'); // بلا settings.geography.view
        Sanctum::actingAs($u);

        $this->getJson('/api/v1/geo/governorates')->assertForbidden();
    }
}
