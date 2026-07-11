<?php

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductAttributeValueFactory extends Factory
{
    protected $model = ProductAttributeValue::class;

    public function definition(): array
    {
        return [
            'attribute_id' => ProductAttribute::factory(),
            'value' => $this->faker->unique()->word(),
            'label' => $this->faker->optional()->word(),
            'color_hex' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
