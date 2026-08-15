<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Database\Seeders\CatalogPermissionSeeder;
use Database\Seeders\ProductPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحة الأصناف: التحديد والحذف الجماعي، وتصفية الظهور على الموقع.
 *
 * الحذف الجماعي إجراء هدّام تصل قائمتُه من المتصفّح — فالصلاحية تُفحص لكل صنف
 * على حدة في الخادم، والعملية داخل معاملة: الكل أو لا شيء.
 */
class ProductBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CatalogPermissionSeeder::class);
        $this->seed(ProductPermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /** @return list<Product> */
    private function products(int $n, array $attributes = []): array
    {
        return Product::factory()->count($n)->active()->create($attributes)->all();
    }

    public function test_selected_products_are_deleted_together(): void
    {
        [$a, $b, $keep] = $this->products(3);

        $this->actingAs($this->admin())
            ->delete(route('admin.products.bulk-destroy'), ['products' => [$a->id, $b->id]])
            ->assertRedirect();

        $this->assertSoftDeleted('products', ['id' => $a->id]);
        $this->assertSoftDeleted('products', ['id' => $b->id]);
        $this->assertNotSoftDeleted('products', ['id' => $keep->id]);
    }

    public function test_an_empty_selection_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->delete(route('admin.products.bulk-destroy'), ['products' => []])
            ->assertSessionHasErrors('products');
    }

    public function test_a_user_without_delete_permission_deletes_nothing(): void
    {
        [$product] = $this->products(1);
        $viewer = User::factory()->create();
        $viewer->assignRole('warehouse');   // يرى الكتالوج ولا يحذفه

        $this->actingAs($viewer)
            ->delete(route('admin.products.bulk-destroy'), ['products' => [$product->id]])
            ->assertRedirect();

        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_the_batch_is_capped(): void
    {
        // سقف يمنع طلبًا مُلفَّقًا يمسح الكتالوج كلّه في نداء واحد.
        $this->actingAs($this->admin())
            ->delete(route('admin.products.bulk-destroy'), ['products' => range(1, 201)])
            ->assertSessionHasErrors('products');
    }

    public function test_an_unknown_id_fails_the_whole_batch(): void
    {
        [$real] = $this->products(1);

        $this->actingAs($this->admin())
            ->delete(route('admin.products.bulk-destroy'), ['products' => [$real->id, 999999]])
            ->assertSessionHasErrors('products.1');

        // لا حذف جزئي: الدفعة تسقط كاملةً.
        $this->assertNotSoftDeleted('products', ['id' => $real->id]);
    }

    public function test_the_visibility_filter_replaces_the_status_filter(): void
    {
        $shown = Product::factory()->active()->create(['name' => 'صنف ظاهر', 'visibility' => 'visible']);
        $hidden = Product::factory()->active()->create(['name' => 'صنف مخفي', 'visibility' => 'hidden']);

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.products.index', ['visibility' => 'visible']))
            ->assertOk()->assertSee($shown->name, false)->assertDontSee($hidden->name, false);

        $this->actingAs($admin)->get(route('admin.products.index', ['visibility' => 'hidden']))
            ->assertOk()->assertSee($hidden->name, false)->assertDontSee($shown->name, false);

        // بلا تصفية: الاثنان.
        $this->actingAs($admin)->get(route('admin.products.index'))
            ->assertOk()->assertSee($shown->name, false)->assertSee($hidden->name, false);
    }

    public function test_the_bulk_route_is_not_swallowed_by_the_resource_route(): void
    {
        // «products/bulk» يشبه «products/{product}» — الترتيب في ملف المسارات
        // هو ما يمنع ابتلاعه، وكسرُه صامت.
        $this->assertSame(
            route('admin.products.bulk-destroy'),
            url('/admin/products/bulk'),
        );
    }
}
