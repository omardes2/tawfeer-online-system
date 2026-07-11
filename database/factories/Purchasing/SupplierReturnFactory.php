<?php

namespace Database\Factories\Purchasing;

use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierReturn;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierReturnFactory extends Factory
{
    protected $model = SupplierReturn::class;

    public function definition(): array
    {
        return [
            'number' => 'PRET-'.$this->faker->unique()->numberBetween(100000, 999999),
            'supplier_id' => Supplier::factory(),
            'warehouse_id' => Warehouse::factory(),
            'status' => 'draft',
        ];
    }
}
