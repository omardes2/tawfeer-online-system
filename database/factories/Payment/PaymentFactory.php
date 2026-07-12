<?php

namespace Database\Factories\Payment;

use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\PaymentMethod;
use App\Modules\Payment\Models\Payment;
use App\Modules\Sales\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'number' => 'PAY-'.$this->faker->unique()->numberBetween(100000, 999999),
            'order_id' => Order::factory(),
            'branch_id' => Branch::factory(),
            'payment_method_id' => PaymentMethod::factory(),
            'amount' => 100,
            'status' => 'pending',
        ];
    }
}
