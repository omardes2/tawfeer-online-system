<?php

namespace Database\Factories\Purchasing;

use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'code' => strtoupper($this->faker->unique()->bothify('SUP-####')),
            'email' => $this->faker->optional()->companyEmail(),
            'phone' => $this->faker->numerify('05########'),
            'payment_terms_days' => 30,
            'credit_limit' => 0,
            'opening_balance' => 0,
            'is_active' => true,
        ];
    }
}
