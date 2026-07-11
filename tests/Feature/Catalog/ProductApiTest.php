<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductTag;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Foundation\Models\AuditLog;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductApiTest extends TestCase
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

    private function withRole(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => Category::factory()->create()->id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'قميص قطني',
            'sku' => 'SKU-001',
        ], $overrides);
    }

    public function test_unauthenticated_rejected(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
    }

    public function test_sales_can_view_but_not_create(): void
    {
        Sanctum::actingAs($this->withRole('sales'));
        $this->getJson('/api/v1/products')->assertOk();
        $this->postJson('/api/v1/products', $this->payload())->assertForbidden();
    }

    public function test_admin_creates_product_with_uuid_slug_audit(): void
    {
        Sanctum::actingAs($this->admin());
        $before = AuditLog::where('auditable_type', Product::class)->count();

        $res = $this->postJson('/api/v1/products', $this->payload())->assertCreated();

        $res->assertJsonPath('data.name', 'قميص قطني');
        $this->assertNotEmpty($res->json('data.id'));   // uuid
        $this->assertNotEmpty($res->json('data.slug'));
        $this->assertGreaterThan($before, AuditLog::where('auditable_type', Product::class)->count());
    }

    public function test_response_exposes_uuid_not_id(): void
    {
        Sanctum::actingAs($this->admin());
        $product = Product::factory()->create();
        $this->getJson('/api/v1/products/'.$product->uuid)->assertOk()->assertJsonPath('data.id', $product->uuid);
    }

    public function test_sku_and_slug_must_be_unique(): void
    {
        Sanctum::actingAs($this->admin());
        Product::factory()->create(['sku' => 'DUP-SKU', 'slug' => 'dup-slug']);

        $this->postJson('/api/v1/products', $this->payload(['sku' => 'DUP-SKU']))
            ->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->postJson('/api/v1/products', $this->payload(['sku' => 'X1', 'slug' => 'dup-slug']))
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_category_and_unit_are_required(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/products', ['name' => 'x', 'sku' => 'Y'])
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'unit_id']);
    }

    public function test_status_active_syncs_is_active(): void
    {
        Sanctum::actingAs($this->admin());
        $res = $this->postJson('/api/v1/products', $this->payload(['status' => 'active']))->assertCreated();
        $this->assertTrue($res->json('data.is_active'));

        $uuid = $res->json('data.id');
        $this->patchJson('/api/v1/products/'.$uuid, ['status' => 'draft'])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_can_sync_tags_and_attributes(): void
    {
        Sanctum::actingAs($this->admin());
        $tag = ProductTag::factory()->create();
        $attr = ProductAttribute::factory()->create();

        $res = $this->postJson('/api/v1/products', $this->payload([
            'tag_ids' => [$tag->id], 'attribute_ids' => [$attr->id],
        ]))->assertCreated();

        $product = Product::where('uuid', $res->json('data.id'))->first();
        $this->assertTrue($product->tags->contains($tag));
        $this->assertTrue($product->attributes->contains($attr));
    }

    public function test_invalid_status_rejected_and_null_not_null_field_is_422(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/products', $this->payload(['status' => 'bogus']))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson('/api/v1/products', $this->payload(['sort_order' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('sort_order');
    }

    public function test_filters_by_category_brand_tag_status_featured(): void
    {
        Sanctum::actingAs($this->admin());
        $cat = Category::factory()->create();
        $brand = Brand::factory()->create();
        $tag = ProductTag::factory()->create();
        $p = Product::factory()->create(['category_id' => $cat->id, 'brand_id' => $brand->id, 'status' => 'active', 'is_featured' => true]);
        $p->tags()->attach($tag->id);
        Product::factory()->create(); // آخر لا يطابق

        $this->getJson('/api/v1/products?category='.$cat->uuid)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products?brand='.$brand->uuid)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products?tag='.$tag->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products?status=active')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products?is_featured=1')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_search_by_name_sku_barcode(): void
    {
        Sanctum::actingAs($this->admin());
        Product::factory()->create(['name' => 'حذاء رياضي مميز', 'sku' => 'FIND-1']);
        Product::factory()->create();

        $this->getJson('/api/v1/products?search=رياضي مميز')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products?search=FIND-1')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_pagination_capped(): void
    {
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/products?per_page=100000')->assertOk()
            ->assertJsonPath('meta.per_page', fn ($v) => $v <= 100);
    }

    public function test_cost_fields_hidden_without_permission(): void
    {
        // المحاسب يملك pricing.view_cost؛ المبيعات لا.
        Sanctum::actingAs($this->withRole('accountant'));
        $product = Product::factory()->create();
        $this->getJson('/api/v1/products/'.$product->uuid)->assertOk()->assertJsonStructure(['data' => ['cost_price', 'average_cost']]);

        Sanctum::actingAs($this->withRole('sales'));
        $this->getJson('/api/v1/products/'.$product->uuid)->assertOk()->assertJsonMissingPath('data.cost_price');
    }

    public function test_soft_delete(): void
    {
        Sanctum::actingAs($this->admin());
        $product = Product::factory()->create();
        $this->deleteJson('/api/v1/products/'.$product->uuid)->assertOk();
        $this->assertSoftDeleted($product);
    }
}
