<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrandApiTest extends TestCase
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

    public function test_sales_cannot_create_brand(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('sales');
        Sanctum::actingAs($u);

        $this->postJson('/api/v1/brands', ['name' => 'Sony'])->assertForbidden();
    }

    public function test_admin_can_crud_brand(): void
    {
        Sanctum::actingAs($this->admin());

        $res = $this->postJson('/api/v1/brands', ['name' => 'Samsung'])->assertCreated();
        $uuid = $res->json('data.id');
        $this->assertNotEmpty($res->json('data.slug'));

        $this->getJson('/api/v1/brands/'.$uuid)->assertOk()->assertJsonPath('data.name', 'Samsung');
        $this->patchJson('/api/v1/brands/'.$uuid, ['name' => 'Samsung KSA'])->assertOk()
            ->assertJsonPath('data.name', 'Samsung KSA');
        $this->deleteJson('/api/v1/brands/'.$uuid)->assertOk();
        $this->assertSoftDeleted('brands', ['name' => 'Samsung KSA']);
    }

    public function test_duplicate_slug_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        Brand::factory()->create(['slug' => 'dupbrand']);

        $this->postJson('/api/v1/brands', ['name' => 'x', 'slug' => 'dupbrand'])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_search_and_filter(): void
    {
        Sanctum::actingAs($this->admin());
        Brand::factory()->create(['name' => 'ماركة مميزة', 'is_active' => true]);
        Brand::factory()->create(['is_active' => false]);

        $this->getJson('/api/v1/brands?search=مميزة')->assertOk()->assertJsonPath('data.0.name', 'ماركة مميزة');
        $this->getJson('/api/v1/brands?is_active=0')->assertOk()->assertJsonCount(1, 'data');
    }
}
