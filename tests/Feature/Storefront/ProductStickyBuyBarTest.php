<?php

namespace Tests\Feature\Storefront;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الشريط اللاصق أسفل صفحة المنتج على الجوّال.
 *
 * كان زرّه مكتوبًا عليه «أضف إلى السلة» بينما هو رابط تمرير (`#sf-buy`) لا يضيف
 * شيئًا — والأسوأ أن هدف التمرير كان يستقرّ خلف الترويسة اللاصقة، فيبدو للزبون
 * أن الضغط لم يفعل شيئًا.
 *
 * والشريط الآن حاضر طوال تصفّح الصفحة على الجوّال، فأُزيل زرّ الشراء المكرّر من
 * أعلى الصفحة هناك — ويبقى على الحواسيب حيث لا شريط لاصق.
 */
class ProductStickyBuyBarTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
    }

    private function stockedProduct(): Product
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => 100]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => 100]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 10, 50);

        return $product->fresh();
    }

    public function test_simple_product_sticky_bar_adds_to_cart_instead_of_scrolling(): void
    {
        $product = $this->stockedProduct();

        $html = $this->get(route('storefront.product', $product->slug))->assertOk()->getContent();

        // لا رابط تمرير: الزرّ العائم يضيف فعلًا.
        $this->assertStringNotContainsString('href="#sf-buy"', $html);

        // زرّ الإضافة يُرسَل مرّتين: للشريط (جوّال) وللحواسيب — وكلٌّ يظهر في مقاسه.
        $this->assertSame(2, substr_count($html, '@click="add()"'));
    }

    public function test_sticky_bar_is_always_visible_not_scroll_triggered(): void
    {
        $product = $this->stockedProduct();

        $html = $this->get(route('storefront.product', $product->slug))->assertOk()->getContent();

        // كان يظهر عند التمرير فقط عبر IntersectionObserver — الآن حاضر دائمًا.
        $this->assertStringNotContainsString('IntersectionObserver', $html);
    }

    public function test_duplicate_add_button_is_hidden_on_mobile_only(): void
    {
        $product = $this->stockedProduct();

        $html = $this->get(route('storefront.product', $product->slug))->assertOk()->getContent();

        // زرّ الشراء الأصلي يختفي على الجوّال (الشريط يحمله) ويبقى على الحواسيب.
        $this->assertStringContainsString('hidden lg:inline-flex', $html);
    }

    public function test_quantity_control_stays_below_the_product_on_mobile(): void
    {
        $product = $this->stockedProduct();

        $html = $this->get(route('storefront.product', $product->slug))->assertOk()->getContent();

        // محدّد الكمية ليس تكرارًا لزرّ الإضافة بل تحكّم بما في السلة، فلا يُخفى:
        // الكتلة نفسها بلا `hidden`، والإخفاء مقصور على زرّ «أضف» وحده.
        $this->assertDoesNotMatchRegularExpression(
            '/<div class="[^"]*hidden lg:block[^"]*" id="sf-buy"/',
            $html,
        );
        $this->assertStringContainsString('id="sf-buy"', $html);
    }

    public function test_add_to_cart_raises_a_toast(): void
    {
        $product = $this->stockedProduct();

        $html = $this->get(route('storefront.product', $product->slug))->assertOk()->getContent();

        // التنبيه يُطلَق من مخزن السلة، ونصّه يصل من ملفّات اللغة لا من JS.
        $this->assertStringContainsString('storefront:toast', $html);
        $this->assertStringContainsString(__('storefront.added_to_cart'), $html);
        $this->assertStringContainsString(__('storefront.view_cart'), $html);
    }

    public function test_sticky_bar_hides_the_base_price_for_products_with_options(): void
    {
        $product = $this->optionsProduct();

        $html = $this->get(route('storefront.product', $product->slug))->assertOk()->getContent();

        // سعر المنتج ذي الخيارات يتبع المقاس المختار؛ عرض سعر أساسي في الشريط
        // كان يناقض ما يظهر أعلى الصفحة بعد الاختيار. (`sf-price` يبقى للسعر
        // الرئيسي أعلى الصفحة، فيُفحص أنه لم يُكرَّر داخل الشريط.)
        $this->assertStringContainsString('<div data-buybar', $html);
        $this->assertStringContainsString(__('storefront.choose_options'), $html);

        $bar = substr($html, strpos($html, '<div data-buybar'));
        $bar = substr($bar, 0, strpos($bar, '</div>') + 6);
        $this->assertStringNotContainsString('sf-price', $bar);
    }

    private function optionsProduct(): Product
    {
        $product = $this->stockedProduct();

        $attribute = ProductAttribute::create(['slug' => 'size', 'name' => 'المقاس', 'is_active' => true]);
        $product->attributes()->syncWithoutDetaching([$attribute->id]);

        foreach (['S', 'M'] as $i => $label) {
            $value = ProductAttributeValue::create([
                'attribute_id' => $attribute->id, 'slug' => 'size-'.strtolower($label),
                'value' => $label, 'label' => $label, 'is_active' => true,
            ]);
            $variant = ProductVariant::create([
                'product_id' => $product->id, 'sku' => $product->sku.'-'.$label,
                'retail_price' => 100 + $i * 10, 'is_active' => true,
            ]);
            $variant->attributeValues()->syncWithoutDetaching([$value->id]);
            app(InventoryService::class)->receive($variant->fresh(), $this->warehouse, 5, 50);
        }

        return $product->fresh();
    }

    public function test_product_with_options_scrolls_to_the_picker_with_an_honest_label(): void
    {
        $product = $this->optionsProduct();

        $html = $this->get(route('storefront.product', $product->slug))->assertOk()->getContent();

        // لا يُمكن الإضافة قبل اختيار المقاس، فالنصّ يقول ذلك بدل وعدٍ لا يفي به.
        $this->assertStringContainsString('href="#sf-buy"', $html);
        $this->assertStringContainsString(__('storefront.choose_options'), $html);
        $this->assertStringNotContainsString('>'.__('storefront.add_to_cart').'</a>', $html);
    }

    public function test_out_of_stock_notice_stays_visible_on_mobile(): void
    {
        // «غير متوفّر» إخبار لا زرّ، فلا يُخفى على الجوّال وإلّا اختفى سبب تعذّر الشراء.
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => 100]);

        $html = $this->get(route('storefront.product', $product->slug))->assertOk()->getContent();

        $this->assertStringContainsString('id="sf-buy"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<div class="[^"]*hidden lg:block[^"]*" id="sf-buy"/',
            $html,
        );
    }

    public function test_scroll_target_clears_the_sticky_header(): void
    {
        $product = $this->stockedProduct();

        $html = $this->get(route('storefront.product', $product->slug))->assertOk()->getContent();

        // بلا هامش تمرير كان الهدف يستقرّ عند أعلى الشاشة تمامًا — خلف الترويسة.
        $this->assertStringContainsString('scroll-mt-32', $html);
    }
}
