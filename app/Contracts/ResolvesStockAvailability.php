<?php

namespace App\Contracts;

use App\Application\Orders\StockAvailabilityDecision;
use App\Models\Order;

interface ResolvesStockAvailability
{
    public function resolve(Order $order): StockAvailabilityDecision;
}
