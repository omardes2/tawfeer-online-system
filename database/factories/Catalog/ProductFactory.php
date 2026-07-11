<?php

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(3, true);

        return [
            'category_id' => Category::factory(),
            'unit_id' => Unit::factory(),
            'brand_id' => null,
            'name' => $name,
            'name_en' => ucwords($name),
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 999999),
            'sku' => strtoupper($this->faker->unique()->bothify('SKU-#####')),
            'barcode' => $this->faker->optional()->ean13(),
            'type' => 'simple',
            'short_description' => $this->faker->optional()->sentence(),
            'description' => $this->faker->optional()->paragraph(),
            'status' => 'draft',
            'visibility' => 'visible',
            'is_featured' => false,
            'sort_order' => 0,
            'is_active' => false,
        ];
    }

    public function configure(): static
    {
        // كل منتج يحصل على متغيّر افتراضي (ADR-024) كما في ProductService.
        return $this->afterCreating(function (Product $product) {
            if (! $product->variants()->exists()) {
                $product->variants()->create([
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'is_default' => true,
                    'is_active' => true,
                ]);
            }
        });
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active', 'is_active' => true]);
    }

    public function withBrand(): static
    {
        return $this->state(fn () => ['brand_id' => Brand::factory()]);
    }
}
