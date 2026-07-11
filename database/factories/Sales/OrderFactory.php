<?php

namespace Database\Factories\Sales;

use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'number' => 'SO-'.$this->faker->unique()->numberBetween(100000, 999999),
            'branch_id' => Branch::factory(),
            'warehouse_id' => Warehouse::factory(),
            'customer_name' => $this->faker->name(),
            'customer_phone' => $this->faker->numerify('01#########'),
            'channel' => 'manual',
            'status' => 'draft',
            'subtotal' => 0,
            'total' => 0,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => 'confirmed', 'confirmed_at' => now()]);
    }
}
