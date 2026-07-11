<?php

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductAttributeFactory extends Factory
{
    protected $model = ProductAttribute::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->word();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 999999),
            'type' => $this->faker->randomElement(['select', 'color', 'text', 'number']),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
