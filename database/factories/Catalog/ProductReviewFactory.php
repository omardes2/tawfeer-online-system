<?php

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductReview;
use App\Modules\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductReviewFactory extends Factory
{
    protected $model = ProductReview::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'customer_id' => Customer::factory(),
            'order_id' => null,
            'rating' => $this->faker->numberBetween(1, 5),
            'title' => $this->faker->optional()->sentence(3),
            'body' => $this->faker->optional()->paragraph(),
            'status' => ProductReview::PENDING,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => ProductReview::APPROVED, 'moderated_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => ProductReview::REJECTED, 'moderated_at' => now()]);
    }
}
