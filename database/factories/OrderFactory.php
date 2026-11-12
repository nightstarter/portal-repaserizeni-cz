<?php

namespace Database\Factories;

use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Enums\RoutingCode;
use App\Enums\TechnicalType;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        $number = fake()->unique()->numerify('9#####');

        return [
            'order_no' => $number,
            'source' => OrderSource::ESHOP,
            'source_order_id' => $number,
            'order_type' => OrderType::CUSTOM_REBUILD,
            'technical_type' => TechnicalType::EPS,
            'routing' => RoutingCode::ARGO,
            'product_code' => fake()->bothify('REP-####'),
            'product_name_snapshot' => 'Repasované řízení',
            'vin' => strtoupper(fake()->bothify('?????????????????')),
            'vehicle' => fake()->randomElement(['Škoda Octavia', 'VW Passat', 'Ford Focus']),
            'customer_handover' => 'GLS',
            'product_verification_status' => 'UNVERIFIED',
            'business_snapshot' => ['currency' => 'CZK'],
            'imported_at' => now(),
        ];
    }

    public function stockRebuild(): static
    {
        return $this->state(fn (): array => ['order_type' => OrderType::STOCK_REBUILD]);
    }

    public function buyout(): static
    {
        return $this->state(fn (): array => [
            'source' => OrderSource::SYSTEM_BUYOUT,
            'source_order_id' => null,
            'order_type' => OrderType::BUYOUT,
        ]);
    }
}
