<?php

namespace Database\Factories\Shipping;

use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Modules\Shipping\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'number' => 'SHP-'.$this->faker->unique()->numberBetween(100000, 999999),
            'order_id' => Order::factory(),
            'branch_id' => Branch::factory(),
            'warehouse_id' => Warehouse::factory(),
            'status' => 'not_shipped',
            'recipient_name' => $this->faker->name(),
            'recipient_phone' => $this->faker->numerify('01#########'),
            'shipping_cost' => 0,
            'cost_source' => 'pending',
        ];
    }
}
