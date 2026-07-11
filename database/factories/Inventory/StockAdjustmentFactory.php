<?php

namespace Database\Factories\Inventory;

use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\StockAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockAdjustmentFactory extends Factory
{
    protected $model = StockAdjustment::class;

    public function definition(): array
    {
        return [
            'number' => 'ADJ-'.$this->faker->unique()->numberBetween(100000, 999999),
            'warehouse_id' => Warehouse::factory(),
            'type' => 'recount',
            'status' => 'draft',
        ];
    }
}
