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
 * أن الضغط لم يفعل شيئًا. هذه الاختبارات تثبّت السلوكين الصحيحين.
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

        // زرّ الإضافة يظهر مرّتين: الأصلي في منطقة الشراء، والعائم في الشريط.
        $this->assertSame(2, substr_count($html, '@click="add()"'));
    }

    public function test_product_with_options_scrolls_to_the_picker_with_an_honest_label(): void
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

        $html = $this->get(route('storefront.product', $product->fresh()->slug))->assertOk()->getContent();

        // لا يُمكن الإضافة قبل اختيار المقاس، فالنصّ يقول ذلك بدل وعدٍ لا يفي به.
        $this->assertStringContainsString('href="#sf-buy"', $html);
        $this->assertStringContainsString(__('storefront.choose_options'), $html);
        $this->assertStringNotContainsString('>'.__('storefront.add_to_cart').'</a>', $html);
    }

    public function test_scroll_target_clears_the_sticky_header(): void
    {
        $product = $this->stockedProduct();

        $html = $this->get(route('storefront.product', $product->slug))->assertOk()->getContent();

        // بلا هامش تمرير كان الهدف يستقرّ عند أعلى الشاشة تمامًا — خلف الترويسة.
        $this->assertStringContainsString('scroll-mt-32', $html);
    }
}
