<?php

namespace Database\Factories\Purchasing;

use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class GoodsReceiptFactory extends Factory
{
    protected $model = GoodsReceipt::class;

    public function definition(): array
    {
        return [
            'number' => 'GRN-'.$this->faker->unique()->numberBetween(100000, 999999),
            'purchase_order_id' => PurchaseOrder::factory(),
            'warehouse_id' => Warehouse::factory(),
            'status' => 'draft',
            'additional_cost' => 0,
        ];
    }
}
