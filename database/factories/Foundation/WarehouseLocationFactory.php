<?php

namespace Database\Factories\Foundation;

use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Foundation\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarehouseLocationFactory extends Factory
{
    protected $model = WarehouseLocation::class;

    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'parent_id' => null,
            'code' => strtoupper($this->faker->unique()->bothify('LOC-####')),
            'name' => 'موقع '.$this->faker->word(),
            'type' => 'bin',
            'is_active' => true,
        ];
    }
}
