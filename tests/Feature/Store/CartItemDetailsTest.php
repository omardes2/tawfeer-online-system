<?php

namespace Tests\Feature\Store;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Store\Models\Cart;
use App\Modules\Store\Services\CartService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * تفاصيل بنود السلة في الواجهة البرمجية.
 *
 * كانت الحمولة تحمل رمز المنتج (SKU) فقط، فيرى الزبون «P-W9DEMWZL» مكان اسم
 * المنتج في سلّته. `name` و`options` و`image` إضافات لا تكسر أي مستهلك قائم —
 * المفاتيح السابقة كلّها باقية كما هي.
 */
class CartItemDetailsTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
    }

    private function cartWith(ProductVariant $variant): Cart
    {
        $cart = Cart::create([
            'session_token' => (string) Str::uuid(),
            'branch_id' => Branch::default()->id,
            'status' => 'active',
        ]);
        app(CartService::class)->addItem($cart, $variant->fresh(), 1);

        return $cart->fresh();
    }

    private function stocked(array $attributes = []): Product
    {
        $product = Product::factory()->active()->create($attributes + [
            'visibility' => 'visible', 'retail_price' => 100,
        ]);
        app(InventoryService::class)->receive($product->defaultVariant, $this->warehouse, 10, 50);

        return $product->fresh();
    }

    public function test_cart_item_carries_the_product_name(): void
    {
        $product = $this->stocked(['name' => 'حبل إضاءة']);
        $cart = $this->cartWith($product->defaultVariant);

        $this->withHeaders(['X-Cart-Token' => $cart->session_token])
            ->getJson('/api/v1/store/cart')
            ->assertOk()
            ->assertJsonPath('data.items.0.name', 'حبل إضاءة')
            // المفاتيح السابقة باقية — لا كسر لأي مستهلك.
            ->assertJsonPath('data.items.0.sku', $product->defaultVariant->sku);
    }

    public function test_cart_item_carries_the_primary_image(): void
    {
        $product = $this->stocked();
        ProductImage::create([
            'product_id' => $product->id, 'path' => 'products/x.jpg',
            'is_primary' => true, 'sort_order' => 0,
        ]);

        $cart = $this->cartWith($product->fresh()->defaultVariant);

        $image = $this->withHeaders(['X-Cart-Token' => $cart->session_token])
            ->getJson('/api/v1/store/cart')
            ->assertOk()
            ->json('data.items.0.image');

        $this->assertNotNull($image);
        $this->assertStringContainsString('products/x.jpg', $image);
    }

    public function test_cart_item_lists_variant_options(): void
    {
        $product = $this->stocked();

        $attribute = ProductAttribute::create(['slug' => 'size', 'name' => 'المقاس', 'is_active' => true]);
        $value = ProductAttributeValue::create([
            'attribute_id' => $attribute->id, 'slug' => 'size-m',
            'value' => 'M', 'label' => 'M', 'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => $product->sku.'-M',
            'retail_price' => 100, 'is_active' => true,
        ]);
        $variant->attributeValues()->syncWithoutDetaching([$value->id]);
        app(InventoryService::class)->receive($variant->fresh(), $this->warehouse, 5, 50);

        $cart = $this->cartWith($variant);

        // بدون سطر الخيارات يبدو مقاسان من المنتج نفسه بندين متطابقين.
        $this->withHeaders(['X-Cart-Token' => $cart->session_token])
            ->getJson('/api/v1/store/cart')
            ->assertOk()
            ->assertJsonPath('data.items.0.options', 'M');
    }

    public function test_item_without_options_has_a_null_options_line(): void
    {
        $product = $this->stocked();
        $cart = $this->cartWith($product->defaultVariant);

        $this->withHeaders(['X-Cart-Token' => $cart->session_token])
            ->getJson('/api/v1/store/cart')
            ->assertOk()
            ->assertJsonPath('data.items.0.options', null);
    }
}
