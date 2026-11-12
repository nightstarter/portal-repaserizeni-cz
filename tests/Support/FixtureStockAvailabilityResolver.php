<?php

namespace Tests\Support;

use App\Application\Orders\StockAvailabilityDecision;
use App\Contracts\ResolvesStockAvailability;
use App\Models\Order;

final readonly class FixtureStockAvailabilityResolver implements ResolvesStockAvailability
{
    public function __construct(private StockAvailabilityDecision $decision) {}

    public function resolve(Order $order): StockAvailabilityDecision
    {
        return $this->decision;
    }
}
