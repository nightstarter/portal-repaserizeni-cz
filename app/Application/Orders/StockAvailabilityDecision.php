<?php

namespace App\Application\Orders;

use App\Enums\StockAvailabilityStatus;
use Carbon\CarbonImmutable;

final readonly class StockAvailabilityDecision
{
    public function __construct(
        public StockAvailabilityStatus $status,
        public string $sourceReference,
        public ?CarbonImmutable $observedAt = null,
    ) {}
}
