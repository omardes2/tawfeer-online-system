<?php

namespace Database\Factories\Crm;

use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => $this->faker->name(),
            'primary_phone' => $this->faker->unique()->numerify('9665########'),
            'source' => 'manual',
        ];
    }

    public function blocked(): static
    {
        return $this->state(fn () => ['is_blocked' => true, 'blocked_reason' => 'اختبار', 'blocked_at' => now()]);
    }
}
