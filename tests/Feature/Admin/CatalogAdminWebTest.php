<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAdminWebTest extends TestCase
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

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/categories')->assertRedirect('/login');
    }

    public function test_admin_categories_index_renders_rtl(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/categories');
        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('الفئات');
    }

    public function test_sales_can_view_but_sees_no_create_button(): void
    {
        $response = $this->actingAs($this->withRole('sales'))->get('/admin/categories');
        $response->assertOk();
        $response->assertDontSee(route('admin.categories.create'));
    }

    public function test_sales_cannot_open_create_form(): void
    {
        $this->actingAs($this->withRole('sales'))->get('/admin/categories/create')->assertForbidden();
    }

    public function test_admin_can_create_category_via_form(): void
    {
        $response = $this->actingAs($this->admin())->post('/admin/categories', [
            'name' => 'أدوات منزلية',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.categories.index'));
        $this->assertDatabaseHas('categories', ['name' => 'أدوات منزلية']);
    }

    public function test_admin_can_edit_category_via_form(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin())->put('/admin/categories/'.$category->uuid, [
            'name' => 'اسم محدّث',
            'is_active' => '1',
        ])->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'اسم محدّث']);
    }

    public function test_delete_category_with_children_shows_error(): void
    {
        $root = Category::factory()->create();
        Category::factory()->create(['parent_id' => $root->id]);

        $this->actingAs($this->admin())
            ->from(route('admin.categories.index'))
            ->delete('/admin/categories/'.$root->uuid)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $root->id, 'deleted_at' => null]);
    }

    public function test_admin_can_add_attribute_value_via_web(): void
    {
        $attribute = ProductAttribute::factory()->create();

        $this->actingAs($this->admin())
            ->from(route('admin.attributes.edit', $attribute))
            ->post(route('admin.attributes.values.store', $attribute), ['value' => 'كبير'])
            ->assertRedirect();

        $this->assertDatabaseHas('product_attribute_values', ['attribute_id' => $attribute->id, 'value' => 'كبير']);
    }
}
