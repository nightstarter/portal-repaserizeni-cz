<?php

namespace App\Enums;

enum WarrantyState: string
{
    case RECEIVED_AK = 'WARRANTY_RECEIVED_AK';
    case READY_TO_PL = 'WARRANTY_READY_TO_PL';
    case SENT_TO_PL = 'WARRANTY_SENT_TO_PL';
    case RECEIVED_PL = 'WARRANTY_RECEIVED_PL';
    case REPAIR_COMPLETED = 'WARRANTY_REPAIR_COMPLETED';
    case READY_TO_AK = 'WARRANTY_READY_TO_AK';
    case SENT_TO_AK = 'WARRANTY_SENT_TO_AK';
    case RECEIVED_AK_BACK = 'WARRANTY_RECEIVED_AK_BACK';
    case SENT_TO_CUSTOMER = 'WARRANTY_SENT_TO_CUSTOMER';
    case PICKED_UP_BY_CUSTOMER = 'WARRANTY_PICKED_UP_BY_CUSTOMER';

    public function isTerminal(): bool
    {
        return in_array($this, [self::SENT_TO_CUSTOMER, self::PICKED_UP_BY_CUSTOMER], true);
    }
}
