<?php

namespace Database\Factories\Inventory;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\StockReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockReservationFactory extends Factory
{
    protected $model = StockReservation::class;

    public function definition(): array
    {
        return [
            'variant_id' => ProductVariant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'qty' => 5,
            'status' => 'active',
            'reserved_at' => now(),
        ];
    }
}
