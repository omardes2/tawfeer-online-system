<?php

namespace Database\Factories\Foundation;

use App\Modules\Foundation\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        return [
            'name' => 'الدفع عند الاستلام',
            'code' => 'cod_'.$this->faker->unique()->numberBetween(1000, 9999),
            'driver' => 'offline',
            'type' => 'offline',
            'is_active' => true,
        ];
    }
}
