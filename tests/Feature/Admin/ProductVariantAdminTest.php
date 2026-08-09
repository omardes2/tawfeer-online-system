<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductVariantAdminTest extends TestCase
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

    /** @return array{0: Product, 1: array<string,int>} منتج + خريطة تسمية→معرّف القيمة */
    private function productWithSizes(array $sizes = ['S', 'M']): array
    {
        $product = Product::factory()->create();
        $attribute = ProductAttribute::factory()->create(['name' => 'مقاسات']);

        $values = [];
        foreach ($sizes as $s) {
            $values[$s] = ProductAttributeValue::create([
                'attribute_id' => $attribute->id, 'value' => $s, 'label' => $s, 'is_active' => true,
            ])->id;
        }

        return [$product, $values];
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
    }

    private function onHand(int $variantId): float
    {
        return (float) InventoryStock::where('variant_id', $variantId)
            ->where('warehouse_id', $this->warehouse()->id)->value('on_hand');
    }

    public function test_sync_creates_variants_with_price_and_stock(): void
    {
        [$product, $v] = $this->productWithSizes(['S', 'M']);

        $this->actingAs($this->admin())->post(route('admin.products.variants.sync', $product), [
            'combos' => [
                ['values' => [$v['S']], 'price' => 40, 'stock' => 5],
                ['values' => [$v['M']], 'price' => 45, 'stock' => 3],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, $product->variants()->optionVariants()->count());

        $small = $product->variants()->optionVariants()->get()
            ->first(fn ($x) => $x->attributeValues->pluck('id')->contains($v['S']));
        $this->assertEquals(40, $small->retail_price);
        $this->assertEqualsWithDelta(5, $this->onHand($small->id), 0.001);
    }

    public function test_sync_updates_existing_variant_in_place(): void
    {
        [$product, $v] = $this->productWithSizes(['S']);

        $this->actingAs($this->admin())->post(route('admin.products.variants.sync', $product), [
            'combos' => [['values' => [$v['S']], 'price' => 40, 'stock' => 5]],
        ])->assertRedirect();

        $variant = $product->variants()->optionVariants()->first();

        // إعادة المزامنة بنفس التركيبة بسعر/كمية مختلفين → تحديث في المكان (لا نسخة جديدة).
        $this->actingAs($this->admin())->post(route('admin.products.variants.sync', $product), [
            'combos' => [['values' => [$v['S']], 'price' => 60, 'stock' => 9]],
        ])->assertRedirect();

        $this->assertSame(1, $product->variants()->optionVariants()->count());
        $variant->refresh();
        $this->assertEquals(60, $variant->retail_price);
        $this->assertEqualsWithDelta(9, $this->onHand($variant->id), 0.001);
    }

    public function test_sync_removes_dropped_combinations(): void
    {
        [$product, $v] = $this->productWithSizes(['S', 'M']);

        $this->actingAs($this->admin())->post(route('admin.products.variants.sync', $product), [
            'combos' => [
                ['values' => [$v['S']], 'price' => 40, 'stock' => 5],
                ['values' => [$v['M']], 'price' => 45, 'stock' => 3],
            ],
        ])->assertRedirect();

        $removed = $product->variants()->optionVariants()->get()
            ->first(fn ($x) => $x->attributeValues->pluck('id')->contains($v['M']));

        // مزامنة بتركيبة واحدة فقط → تُحذف الأخرى.
        $this->actingAs($this->admin())->post(route('admin.products.variants.sync', $product), [
            'combos' => [['values' => [$v['S']], 'price' => 40, 'stock' => 5]],
        ])->assertRedirect();

        $this->assertSame(1, $product->variants()->optionVariants()->count());
        $this->assertSoftDeleted('product_variants', ['id' => $removed->id]);
    }

    public function test_sync_requires_values_per_combo(): void
    {
        [$product] = $this->productWithSizes();

        $this->actingAs($this->admin())->post(route('admin.products.variants.sync', $product), [
            'combos' => [['values' => [], 'price' => 10, 'stock' => 1]],
        ])->assertSessionHasErrors('combos.0.values');
    }
}
