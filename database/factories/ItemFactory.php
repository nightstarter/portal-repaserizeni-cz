<?php

namespace Database\Factories;

use App\Enums\ItemRole;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'role' => ItemRole::CUSTOMER_ITEM,
            'qr_token' => Str::random(48),
            'current_location' => 'CUSTOMER',
            'inspection_result' => null,
            'technical_attributes' => [],
            'note' => null,
        ];
    }
}
