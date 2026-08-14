<?php

namespace Tests\Feature\Storefront;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Store\Services\CartService;
use App\Modules\Store\Services\StorefrontService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * توافر المنتج ذي المقاسات/الألوان في المتجر.
 *
 * كان التوافر يُقرأ من **المتغيّر الافتراضي وحده**، ومنتج بمقاسات يحمل مخزونه
 * على متغيّرات المقاسات لا على الافتراضي — فيظهر «غير متوفّر» في القوائم رغم
 * توفّر كل المقاسات فعلًا.
 */
class VariantAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
    }

    /** منتج مخزونه على المقاسات فقط، ومتغيّره الافتراضي بصفر. */
    private function productWithStockOnSizesOnly(): Product
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => 150]);

        // الافتراضي بصفر — هذا هو جوهر الحالة المُبلَّغ عنها.
        InventoryStock::where('variant_id', $product->defaultVariant->id)
            ->update(['on_hand' => 0, 'reserved' => 0]);

        $attribute = ProductAttribute::create(['slug' => 'size', 'name' => 'المقاس', 'is_active' => true]);
        $product->attributes()->syncWithoutDetaching([$attribute->id]);

        foreach (['S', 'M', 'L'] as $label) {
            $value = ProductAttributeValue::create([
                'attribute_id' => $attribute->id, 'slug' => 'size-'.strtolower($label),
                'value' => $label, 'label' => $label, 'is_active' => true,
            ]);
            $variant = ProductVariant::create([
                'product_id' => $product->id, 'sku' => $product->sku.'-'.$label,
                'retail_price' => 150, 'is_active' => true,
            ]);
            $variant->attributeValues()->syncWithoutDetaching([$value->id]);
            app(InventoryService::class)->receive($variant->fresh(), $this->warehouse, 7, 90);
        }

        return $product->fresh();
    }

    public function test_availability_sums_all_variants_not_just_the_default(): void
    {
        $product = $this->productWithStockOnSizesOnly();
        $sf = app(StorefrontService::class);

        $this->assertSame(0.0, app(CartService::class)
            ->availableQty($product->defaultVariant));

        // ٣ مقاسات × ٧ = ٢١ — لا صفر.
        $this->assertEqualsWithDelta(21.0, $sf->availableQty($product), 0.001);
        $this->assertTrue($sf->inStock($product));
    }

    public function test_listing_card_offers_options_instead_of_out_of_stock(): void
    {
        $product = $this->productWithStockOnSizesOnly();

        $html = $this->get(route('storefront.shop'))->assertOk()->getContent();

        $this->assertStringContainsString($product->name, $html);
        // البطاقة تقود لصفحة المنتج لاختيار المقاس بدل زرّ إضافة يفشل بالمتغيّر الافتراضي.
        $this->assertStringContainsString(__('storefront.choose_options'), $html);
    }

    public function test_product_page_does_not_render_an_out_of_stock_buy_block(): void
    {
        $product = $this->productWithStockOnSizesOnly();

        $html = $this->get(route('storefront.product', $product->slug))->assertOk()->getContent();

        // يُفحص صندوق الشراء وحده: أقسام التوصيات أسفل الصفحة قد تحوي منتجات
        // نافدة فعلًا، فالبحث في الصفحة كلّها يعطي نتيجة مضلّلة.
        $buyBlock = substr($html, strpos($html, 'id="sf-buy"'));
        $buyBlock = substr($buyBlock, 0, strpos($buyBlock, '</div>') + 6);

        $this->assertStringNotContainsString(__('storefront.out_of_stock'), $buyBlock);
        $this->assertStringContainsString('variantPicker', $html);
    }

    public function test_simple_product_availability_is_unchanged(): void
    {
        // منتج بلا خيارات: السلوك السابق كما هو تمامًا.
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => 100]);
        app(InventoryService::class)->receive($product->defaultVariant, $this->warehouse, 4, 60);

        $sf = app(StorefrontService::class);
        $this->assertEqualsWithDelta(4.0, $sf->availableQty($product->fresh()), 0.001);
    }

    public function test_product_with_no_stock_anywhere_is_still_out_of_stock(): void
    {
        $product = $this->productWithStockOnSizesOnly();
        InventoryStock::whereIn('variant_id', $product->variants->pluck('id'))
            ->update(['on_hand' => 0, 'reserved' => 0]);

        $sf = app(StorefrontService::class);
        $this->assertFalse($sf->inStock($product->fresh()));
    }
}
