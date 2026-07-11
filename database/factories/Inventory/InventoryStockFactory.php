<?php

namespace Database\Factories\Inventory;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryStockFactory extends Factory
{
    protected $model = InventoryStock::class;

    public function definition(): array
    {
        return [
            'variant_id' => ProductVariant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'on_hand' => 0,
            'reserved' => 0,
            'damaged' => 0,
            'returned_pending' => 0,
            'in_transit' => 0,
            'average_cost' => 0,
            'cost_price' => 0,
        ];
    }
}
