<?php

namespace Database\Factories\Foundation;

use App\Modules\Foundation\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'name' => 'فرع '.$this->faker->unique()->city(),
            'code' => strtoupper($this->faker->unique()->bothify('BR-####')),
            'address' => $this->faker->address(),
            'phone' => $this->faker->numerify('05########'),
            'email' => $this->faker->unique()->safeEmail(),
            'tax_number' => $this->faker->numerify('3############'),
            'timezone' => 'Asia/Riyadh',
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
