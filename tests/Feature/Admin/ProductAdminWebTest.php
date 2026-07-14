<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTag;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Store\Services\StorefrontService;
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

    public function test_index_shows_price_stock_sold_columns(): void
    {
        $product = Product::factory()->create(['name' => 'صنف مخزون', 'retail_price' => 99]);
        $res = $this->actingAs($this->admin())->get('/admin/products');
        $res->assertOk();
        $res->assertSee('صنف مخزون');
        $res->assertSee(__('المتوفّرة'));
        $res->assertSee(__('المباعة'));
        $res->assertSee(__('إظهار على الموقع'));
    }

    public function test_toggle_visibility_hides_and_shows_product(): void
    {
        $product = Product::factory()->create(['visibility' => 'visible']);

        $this->actingAs($this->admin())->post(route('admin.products.toggle-visibility', $product))->assertRedirect();
        $this->assertSame('hidden', $product->fresh()->visibility);

        $this->actingAs($this->admin())->post(route('admin.products.toggle-visibility', $product))->assertRedirect();
        $this->assertSame('visible', $product->fresh()->visibility);
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

    public function test_admin_form_can_clear_all_tags(): void
    {
        $product = Product::factory()->create();
        $tag = ProductTag::factory()->create();
        $product->tags()->attach($tag->id);

        // النموذج بلا tag_ids (أُلغي تحديد الكل) يجب أن يمسح الوسوم.
        $this->actingAs($this->admin())->put('/admin/products/'.$product->uuid, $this->fields([
            'sku' => $product->sku,
        ]))->assertRedirect();

        $this->assertSame(0, $product->fresh()->tags()->count());
    }

    public function test_update_prices_sync_to_variant_and_enable_discount(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->put('/admin/products/'.$product->uuid, $this->fields([
            'sku' => $product->sku,
            'retail_price' => 100,
            'promo_price' => 80,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $variant = $product->fresh()->defaultVariant;
        $this->assertEqualsWithDelta(100, (float) $variant->retail_price, 0.01);
        $this->assertEqualsWithDelta(80, (float) $variant->promo_price, 0.01);

        // ينعكس خصمًا في الموقع.
        $sf = app(StorefrontService::class);
        $this->assertTrue($sf->onSale($product->fresh()));
        $this->assertEqualsWithDelta(80, $sf->sellingPrice($product->fresh()), 0.01);
    }

    public function test_promo_price_cannot_exceed_retail(): void
    {
        $product = Product::factory()->create();
        $this->actingAs($this->admin())->put('/admin/products/'.$product->uuid, $this->fields([
            'sku' => $product->sku, 'retail_price' => 100, 'promo_price' => 120,
        ]))->assertSessionHasErrors('promo_price');
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
