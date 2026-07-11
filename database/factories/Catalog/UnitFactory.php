<?php

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'name_en' => $this->faker->unique()->word(),
            'code' => strtoupper($this->faker->unique()->bothify('U-###')),
            'symbol' => $this->faker->randomElement(['pcs', 'kg', 'box', 'ltr']),
            'base_unit_id' => null,
            'conversion_factor' => 1,
            'is_active' => true,
        ];
    }
}
