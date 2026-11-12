<?php

namespace App\Integrations\Stock;

use App\Application\Orders\StockAvailabilityDecision;
use App\Contracts\ResolvesStockAvailability;
use App\Enums\StockAvailabilityStatus;
use App\Models\Order;

final class PendingStockAvailabilityResolver implements ResolvesStockAvailability
{
    public function resolve(Order $order): StockAvailabilityDecision
    {
        return new StockAvailabilityDecision(
            StockAvailabilityStatus::UNKNOWN,
            'NO_TRUSTED_SNAPSHOT',
        );
    }
}
