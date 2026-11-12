<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderCustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'name' => fake()->name(),
            'company' => null,
            'email' => fake()->safeEmail(),
            'phone' => '+420 777 000 111',
            'street' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'country_code' => 'CZ',
        ];
    }
}
