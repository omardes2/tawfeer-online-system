<?php

namespace Database\Factories\Purchasing;

use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        return [
            'number' => 'PO-'.$this->faker->unique()->numberBetween(100000, 999999),
            'supplier_id' => Supplier::factory(),
            'warehouse_id' => Warehouse::factory(),
            'status' => 'draft',
            'order_date' => now()->toDateString(),
            'subtotal' => 0,
            'total' => 0,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved', 'approved_at' => now()]);
    }
}
