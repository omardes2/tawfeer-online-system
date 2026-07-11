<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductAdminWebTest extends TestCase
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

    private function fields(array $o = []): array
    {
        return array_merge([
            'category_id' => Category::factory()->create()->id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'منتج ويب',
            'sku' => 'WEB-1',
            'status' => 'active',
        ], $o);
    }

    public function test_guest_redirected(): void
    {
        $this->get('/admin/products')->assertRedirect('/login');
    }

    public function test_index_renders_rtl(): void
    {
        $res = $this->actingAs($this->admin())->get('/admin/products');
        $res->assertOk();
        $res->assertSee('dir="rtl"', false);
        $res->assertSee('المنتجات');
    }

    public function test_sales_cannot_open_create(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('sales');
        $this->actingAs($u)->get('/admin/products/create')->assertForbidden();
    }

    public function test_admin_creates_via_form_and_redirects_to_edit(): void
    {
        $res = $this->actingAs($this->admin())->post('/admin/products', $this->fields());
        $res->assertRedirect();
        $this->assertDatabaseHas('products', ['sku' => 'WEB-1', 'is_active' => true]);
    }

    public function test_admin_uploads_image_via_web(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();

        $this->actingAs($this->admin())
            ->from(route('admin.products.edit', $product))
            ->post(route('admin.products.images.store', $product), ['image' => UploadedFile::fake()->image('p.jpg')])
            ->assertRedirect();

        $this->assertSame(1, $product->images()->count());
    }
}
