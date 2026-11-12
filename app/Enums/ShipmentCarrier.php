<?php

namespace App\Enums;

enum ShipmentCarrier: string
{
    case GLS = 'GLS';
    case DPD = 'DPD';
    case SVOZ_AUTOKLUBY = 'SVOZ_AUTOKLUBY';

    public function requiresTracking(): bool
    {
        return $this !== self::SVOZ_AUTOKLUBY;
    }
}
