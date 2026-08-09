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

    private function productWithSizes(array $sizes = ['S', 'M']): array
    {
        $product = Product::factory()->create();
        $attribute = ProductAttribute::factory()->create(['name' => 'مقاسات']);
        $product->attributes()->attach($attribute->id);

        $valueIds = collect($sizes)->map(fn ($s) => ProductAttributeValue::create([
            'attribute_id' => $attribute->id, 'value' => $s, 'label' => $s, 'is_active' => true,
        ])->id)->all();

        return [$product, $valueIds];
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
    }

    public function test_admin_generates_variants_from_values(): void
    {
        [$product, $valueIds] = $this->productWithSizes(['S', 'M', 'L']);

        $this->actingAs($this->admin())
            ->post(route('admin.products.variants.generate', $product), ['value_ids' => $valueIds])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(3, $product->variants()->optionVariants()->count());
    }

    public function test_generate_requires_at_least_one_value(): void
    {
        [$product] = $this->productWithSizes();

        $this->actingAs($this->admin())
            ->post(route('admin.products.variants.generate', $product), ['value_ids' => []])
            ->assertSessionHasErrors('value_ids');
    }

    public function test_admin_updates_variant_price_and_stock(): void
    {
        [$product, $valueIds] = $this->productWithSizes(['S']);
        $this->actingAs($this->admin())
            ->post(route('admin.products.variants.generate', $product), ['value_ids' => $valueIds])->assertRedirect();

        $variant = $product->variants()->optionVariants()->first();

        $this->actingAs($this->admin())
            ->put(route('admin.products.variants.update', [$product, $variant]), [
                'retail_price' => 55, 'stock' => 7, 'is_active' => 1,
            ])->assertRedirect()->assertSessionHasNoErrors();

        $variant->refresh();
        $this->assertEquals(55, $variant->retail_price);

        $onHand = (float) InventoryStock::where('variant_id', $variant->id)
            ->where('warehouse_id', $this->warehouse()->id)->value('on_hand');
        $this->assertEqualsWithDelta(7, $onHand, 0.001);
    }

    public function test_admin_deletes_variant(): void
    {
        [$product, $valueIds] = $this->productWithSizes(['S']);
        $this->actingAs($this->admin())
            ->post(route('admin.products.variants.generate', $product), ['value_ids' => $valueIds])->assertRedirect();

        $variant = $product->variants()->optionVariants()->first();

        $this->actingAs($this->admin())
            ->delete(route('admin.products.variants.destroy', [$product, $variant]))
            ->assertRedirect();

        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
    }
}
