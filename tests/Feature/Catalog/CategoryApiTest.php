<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Foundation\Models\AuditLog;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryApiTest extends TestCase
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

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/categories')->assertUnauthorized();
    }

    public function test_sales_can_view_but_not_create(): void
    {
        Sanctum::actingAs($this->withRole('sales'));
        $this->getJson('/api/v1/categories')->assertOk();
        $this->postJson('/api/v1/categories', ['name' => 'x'])->assertForbidden();
    }

    public function test_admin_can_create_category_with_auto_slug_and_audit(): void
    {
        Sanctum::actingAs($this->admin());
        $before = AuditLog::where('auditable_type', Category::class)->count();

        $res = $this->postJson('/api/v1/categories', ['name' => 'إلكترونيات'])->assertCreated();

        $res->assertJsonPath('data.name', 'إلكترونيات');
        $this->assertNotEmpty($res->json('data.slug'));
        $this->assertNotEmpty($res->json('data.id')); // uuid
        $this->assertGreaterThan($before, AuditLog::where('auditable_type', Category::class)->count());
    }

    public function test_response_exposes_uuid_not_internal_id(): void
    {
        Sanctum::actingAs($this->admin());
        $cat = Category::factory()->create();

        $this->getJson('/api/v1/categories/'.$cat->uuid)
            ->assertOk()->assertJsonPath('data.id', $cat->uuid);
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        Category::factory()->create(['slug' => 'dup-slug']);

        $this->postJson('/api/v1/categories', ['name' => 'x', 'slug' => 'dup-slug'])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_slug_auto_suffixes_when_name_repeats(): void
    {
        Sanctum::actingAs($this->admin());
        $a = $this->postJson('/api/v1/categories', ['name' => 'كتب'])->json('data.slug');
        $b = $this->postJson('/api/v1/categories', ['name' => 'كتب'])->json('data.slug');

        $this->assertNotSame($a, $b);
    }

    public function test_empty_slug_string_is_derived_from_name(): void
    {
        // واجهة الإدارة ترسل slug فارغًا دائمًا؛ يجب اشتقاقه من الاسم لا أن يصير "item".
        Sanctum::actingAs($this->admin());

        $slug = $this->postJson('/api/v1/categories', ['name' => 'إلكترونيات', 'slug' => ''])
            ->assertCreated()->json('data.slug');

        $this->assertNotSame('item', $slug);
        $this->assertNotEmpty($slug);
    }

    public function test_null_sort_order_is_rejected_cleanly_not_500(): void
    {
        // sort_order عمود NOT NULL بافتراضي 0؛ null صريح يُرفض بـ 422 لا 500.
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/categories', ['name' => 'x', 'sort_order' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('sort_order');
    }

    public function test_can_create_nested_category(): void
    {
        Sanctum::actingAs($this->admin());
        $parent = Category::factory()->create();

        $this->postJson('/api/v1/categories', ['name' => 'فرعية', 'parent_id' => $parent->id])
            ->assertCreated();

        $this->assertDatabaseHas('categories', ['parent_id' => $parent->id]);
    }

    public function test_cannot_set_category_as_its_own_parent(): void
    {
        Sanctum::actingAs($this->admin());
        $cat = Category::factory()->create();

        $this->patchJson('/api/v1/categories/'.$cat->uuid, ['parent_id' => $cat->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_cannot_move_category_under_its_descendant(): void
    {
        Sanctum::actingAs($this->admin());
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);

        // نقل الجذر تحت ابنه = دورة
        $this->patchJson('/api/v1/categories/'.$root->uuid, ['parent_id' => $child->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_cannot_delete_category_with_children(): void
    {
        Sanctum::actingAs($this->admin());
        $root = Category::factory()->create();
        Category::factory()->create(['parent_id' => $root->id]);

        $this->deleteJson('/api/v1/categories/'.$root->uuid)->assertUnprocessable();
    }

    public function test_can_soft_delete_leaf_category(): void
    {
        Sanctum::actingAs($this->admin());
        $cat = Category::factory()->create();

        $this->deleteJson('/api/v1/categories/'.$cat->uuid)->assertOk();
        $this->assertSoftDeleted($cat);
    }

    public function test_filter_by_active_and_search_and_roots_only(): void
    {
        Sanctum::actingAs($this->admin());
        $root = Category::factory()->create(['name' => 'جذر فريد', 'is_active' => true]);
        Category::factory()->create(['parent_id' => $root->id, 'is_active' => false]);

        $this->getJson('/api/v1/categories?roots_only=1')->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/categories?is_active=0')->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/categories?search=جذر فريد')->assertOk()
            ->assertJsonPath('data.0.name', 'جذر فريد');
    }

    public function test_pagination_respects_per_page_cap(): void
    {
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/categories?per_page=100000')->assertOk()
            ->assertJsonPath('meta.per_page', fn ($v) => $v <= 100);
    }
}
