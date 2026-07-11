<?php

namespace Database\Factories\Foundation;

use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => 'مستودع '.$this->faker->unique()->word(),
            'code' => strtoupper($this->faker->unique()->bothify('WH-####')),
            'type' => 'main',
            'address' => $this->faker->address(),
            'phone' => $this->faker->numerify('05########'),
            'allow_negative' => false,
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
