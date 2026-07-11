<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\ProductTag;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductTagApiTest extends TestCase
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

    public function test_admin_can_crud_tag(): void
    {
        Sanctum::actingAs($this->admin());
        $res = $this->postJson('/api/v1/tags', ['name' => 'تخفيضات'])->assertCreated();
        $id = $res->json('data.id');
        $this->assertNotEmpty($res->json('data.slug'));

        $this->patchJson('/api/v1/tags/'.$id, ['name' => 'عروض'])->assertOk()->assertJsonPath('data.name', 'عروض');
        $this->deleteJson('/api/v1/tags/'.$id)->assertOk();
        $this->assertDatabaseMissing('product_tags', ['id' => $id]);
    }

    public function test_duplicate_slug_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        ProductTag::factory()->create(['slug' => 'duptag']);
        $this->postJson('/api/v1/tags', ['name' => 'x', 'slug' => 'duptag'])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_sales_cannot_create_tag(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('sales');
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/tags', ['name' => 'x'])->assertForbidden();
    }

    public function test_pagination_and_sorting(): void
    {
        Sanctum::actingAs($this->admin());
        ProductTag::factory()->count(3)->create();
        $this->getJson('/api/v1/tags?sort=name&direction=asc&per_page=2')->assertOk()
            ->assertJsonPath('meta.per_page', 2);
    }
}
