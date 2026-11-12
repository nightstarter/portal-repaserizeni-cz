<?php

namespace App\Enums;

enum StockAvailabilityStatus: string
{
    case AVAILABLE_IN_AK = 'AVAILABLE_IN_AK';
    case NOT_AVAILABLE_IN_AK = 'NOT_AVAILABLE_IN_AK';
    case UNKNOWN = 'UNKNOWN';
    case STALE = 'STALE';

    public function isConclusive(): bool
    {
        return in_array($this, [self::AVAILABLE_IN_AK, self::NOT_AVAILABLE_IN_AK], true);
    }
}
