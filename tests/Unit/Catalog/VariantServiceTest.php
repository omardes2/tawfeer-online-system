<?php

namespace Tests\Unit\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Services\VariantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VariantServiceTest extends TestCase
{
    use RefreshDatabase;

    private function attributeWithValues(string $name, array $values): array
    {
        $attribute = ProductAttribute::factory()->create(['name' => $name]);

        return collect($values)->map(fn ($v) => ProductAttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => $v,
            'label' => $v,
            'is_active' => true,
        ])->id)->all();
    }

    public function test_generates_cartesian_product_of_selected_values(): void
    {
        $product = Product::factory()->create();
        $sizes = $this->attributeWithValues('مقاسات', ['S', 'M', 'L']);
        $colors = $this->attributeWithValues('ألوان', ['أحمر', 'أزرق']);

        $created = app(VariantService::class)->generate($product, array_merge($sizes, $colors));

        // 3 مقاسات × 2 لون = 6 متغيّرات خيارات (عدا الافتراضي).
        $this->assertSame(6, $created);
        $this->assertSame(6, $product->variants()->optionVariants()->count());
    }

    public function test_generation_is_idempotent_and_skips_existing(): void
    {
        $product = Product::factory()->create();
        $sizes = $this->attributeWithValues('مقاسات', ['S', 'M']);

        $svc = app(VariantService::class);
        $first = $svc->generate($product, $sizes);
        $second = $svc->generate($product, $sizes);

        $this->assertSame(2, $first);
        $this->assertSame(0, $second); // لا تكرار
        $this->assertSame(2, $product->variants()->optionVariants()->count());
    }

    public function test_variant_links_its_attribute_values_and_labels(): void
    {
        $product = Product::factory()->create();
        $sizes = $this->attributeWithValues('مقاسات', ['L']);
        $colors = $this->attributeWithValues('ألوان', ['أسود']);

        app(VariantService::class)->generate($product, array_merge($sizes, $colors));

        $variant = $product->variants()->optionVariants()->first();
        $this->assertSame(2, $variant->attributeValues()->count());
        $this->assertStringContainsString('L', $variant->optionLabel());
        $this->assertStringContainsString('أسود', $variant->optionLabel());
        $this->assertStringStartsWith('V-', $variant->sku);
    }
}
