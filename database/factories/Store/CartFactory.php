<?php

namespace Database\Factories\Store;

use App\Modules\Store\Models\Cart;
use Illuminate\Database\Eloquent\Factories\Factory;

class CartFactory extends Factory
{
    protected $model = Cart::class;

    public function definition(): array
    {
        return [
            'status' => 'active',
        ];
    }
}
