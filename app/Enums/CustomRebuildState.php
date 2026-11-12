<?php

namespace App\Enums;

enum CustomRebuildState: string
{
    case WAITING_CUSTOMER_ITEM = 'WAITING_CUSTOMER_ITEM';
    case RECEIVED_AK = 'RECEIVED_AK';
    case SENT_TO_PL = 'SENT_TO_PL';
    case RECEIVED_PL_REPAIR = 'RECEIVED_PL_REPAIR';
    case REPAIRED_PL = 'REPAIRED_PL';
    case SENT_TO_AK = 'SENT_TO_AK';
    case SENT_CUSTOMER = 'SENT_CUSTOMER';
    case READY_PICKUP = 'READY_PICKUP';
    case PICKED_UP = 'PICKED_UP';

    public function isTerminal(): bool
    {
        return in_array($this, [self::SENT_CUSTOMER, self::PICKED_UP], true);
    }
}
