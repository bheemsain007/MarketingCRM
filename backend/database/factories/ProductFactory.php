<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(3, true);

        return [
            'code' => strtoupper(str_replace(' ', '_', $name)),
            'name' => ucwords($name),
            'description' => $this->faker->sentence(),
            'delivery_type' => $this->faker->randomElement(['saas', 'project', 'service']),
            'base_price' => $this->faker->numberBetween(5000, 200000),
            'currency' => 'INR',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
