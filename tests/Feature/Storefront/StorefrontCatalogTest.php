<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Services\VariantService;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StorefrontCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
    }

    private function product(array $attrs = [], float $price = 50, float $stock = 10): Product
    {
        $product = Product::factory()->active()->create(array_merge([
            'retail_price' => $price,
            'visibility' => 'visible',
        ], $attrs));
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => $price]);
        if ($stock > 0) {
            app(InventoryService::class)->receive($variant, $this->warehouse, $stock, $price);
        }

        return $product;
    }

    public function test_home_page_loads_even_with_empty_catalog(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_shop_lists_only_active_and_visible_products(): void
    {
        $visible = $this->product(['name' => 'منتج ظاهر', 'name_en' => 'Visible Product']);
        $hidden = $this->product(['name' => 'منتج مخفي', 'name_en' => 'Hidden Product', 'visibility' => 'hidden']);
        $inactive = Product::factory()->create(['name' => 'منتج غير مفعل', 'visibility' => 'visible']); // is_active=false

        $res = $this->get('/shop')->assertOk();
        $res->assertSee($visible->slug);
        $res->assertDontSee($hidden->slug);
        $res->assertDontSee($inactive->slug);
    }

    public function test_product_details_page_has_seo_and_add_to_cart(): void
    {
        $product = $this->product(['name' => 'كوب قهوة', 'name_en' => 'Coffee Mug', 'meta_description' => 'أفضل كوب قهوة']);

        $res = $this->get("/p/{$product->slug}")->assertOk();
        $res->assertSee($product->name);
        $res->assertSee($product->defaultVariant->uuid); // زر الإضافة يحمل معرّف المتغيّر
        $res->assertSee('"@type":"Product"', false); // بيانات مهيكلة
        $res->assertSee('rel="canonical"', false);
        $res->assertSee('أفضل كوب قهوة'); // meta description
    }

    public function test_product_with_variants_shows_option_selectors(): void
    {
        $product = $this->product(['name' => 'قميص', 'name_en' => 'Shirt']);

        $attribute = ProductAttribute::factory()->create(['name' => 'مقاسات']);
        $product->attributes()->attach($attribute->id);
        $ids = collect(['S', 'L'])->map(fn ($s) => ProductAttributeValue::create([
            'attribute_id' => $attribute->id, 'value' => $s, 'label' => $s, 'is_active' => true,
        ])->id)->all();
        app(VariantService::class)->generate($product, $ids);

        $variant = $product->variants()->optionVariants()->first();
        app(InventoryService::class)->receive($variant, $this->warehouse, 5, 40);

        $res = $this->get("/p/{$product->slug}")->assertOk();
        $res->assertSee('variantPicker', false);   // مكوّن اختيار الخيارات مفعّل
        $res->assertSee($variant->uuid);           // متغيّر الخيار ضمن بيانات الاختيار
    }

    public function test_hidden_or_inactive_product_returns_404(): void
    {
        $hidden = $this->product(['visibility' => 'hidden']);
        $inactive = Product::factory()->create(['visibility' => 'visible']); // is_active=false

        $this->get("/p/{$hidden->slug}")->assertNotFound();
        $this->get("/p/{$inactive->slug}")->assertNotFound();
    }

    public function test_category_page_filters_products(): void
    {
        $catA = Category::factory()->create(['name' => 'إلكترونيات', 'is_active' => true]);
        $catB = Category::factory()->create(['name' => 'ملابس', 'is_active' => true]);
        $inA = $this->product(['category_id' => $catA->id]);
        $inB = $this->product(['category_id' => $catB->id]);

        $res = $this->get("/c/{$catA->slug}")->assertOk();
        $res->assertSee($inA->slug);
        $res->assertDontSee($inB->slug);
    }

    public function test_brand_page_filters_products(): void
    {
        $brand = Brand::factory()->create(['name' => 'سامسونج', 'is_active' => true]);
        $branded = $this->product(['brand_id' => $brand->id]);
        $other = $this->product();

        $res = $this->get("/b/{$brand->slug}")->assertOk();
        $res->assertSee($branded->slug);
        $res->assertDontSee($other->slug);
    }

    public function test_search_finds_by_name(): void
    {
        $match = $this->product(['name' => 'سمّاعة بلوتوث', 'name_en' => 'Bluetooth Headset']);
        $noMatch = $this->product(['name' => 'طاولة خشب', 'name_en' => 'Wooden Table']);

        $res = $this->get('/search?q=بلوتوث')->assertOk();
        $res->assertSee($match->slug);
        $res->assertDontSee($noMatch->slug);
    }

    public function test_price_filter_and_sort(): void
    {
        $cheap = $this->product(['name' => 'رخيص'], price: 20);
        $expensive = $this->product(['name' => 'غالي'], price: 200);

        // فلترة: أقل من 100 → الرخيص فقط.
        $res = $this->get('/shop?max=100')->assertOk();
        $res->assertSee($cheap->slug);
        $res->assertDontSee($expensive->slug);

        // ترتيب تصاعدي: الرخيص قبل الغالي.
        $res2 = $this->get('/shop?sort=price_asc')->assertOk();
        $this->assertLessThan(
            strpos($res2->getContent(), $expensive->slug),
            strpos($res2->getContent(), $cheap->slug),
        );
    }

    public function test_listing_paginates(): void
    {
        $perPage = (int) config('storefront.per_page', 12);
        for ($i = 0; $i < $perPage + 3; $i++) {
            $this->product(['name' => "منتج {$i}"]);
        }

        $res = $this->get('/shop')->assertOk();
        // رابط الصفحة الثانية موجود.
        $res->assertSee('page=2', false);
    }

    public function test_empty_search_shows_empty_state(): void
    {
        $this->product(['name' => 'شيء']);
        $res = $this->get('/search?q=لا-يوجد-هذا-المنتج-إطلاقا')->assertOk();
        $res->assertSee(__('storefront.no_products'));
    }

    public function test_categories_and_brands_index(): void
    {
        $cat = Category::factory()->create(['name' => 'مطبخ', 'is_active' => true]);
        $brand = Brand::factory()->create(['name' => 'إل جي', 'is_active' => true]);

        $this->get('/categories')->assertOk()->assertSee($cat->name);
        $this->get('/brands')->assertOk()->assertSee($brand->name);
    }

    public function test_locale_switch_sets_session_and_direction(): void
    {
        // الافتراضي عربي (RTL).
        $this->get('/')->assertOk()->assertSee('dir="rtl"', false);

        // التبديل للإنجليزية.
        $this->get('/lang/en')->assertRedirect();
        $this->assertEquals('en', session('storefront_locale'));

        $this->get('/')->assertOk()->assertSee('dir="ltr"', false);
    }

    public function test_guest_can_add_storefront_product_to_cart(): void
    {
        $product = $this->product();
        $token = (string) Str::uuid();

        $this->postJson('/api/v1/store/cart/items',
            ['variant' => $product->defaultVariant->uuid, 'qty' => 2],
            ['X-Cart-Token' => $token],
        )->assertOk()->assertJsonPath('data.item_count', 1);
    }

    public function test_authenticated_user_can_add_storefront_product_to_cart(): void
    {
        $product = $this->product();
        Sanctum::actingAs(User::where('email', 'admin@tawfeer.online')->first());

        $this->postJson('/api/v1/store/cart/items',
            ['variant' => $product->defaultVariant->uuid, 'qty' => 1],
        )->assertOk()->assertJsonPath('data.item_count', 1);
    }
}
