<?php

namespace App\Enums;

enum StockRebuildState: string
{
    case PENDING_STOCK_CHECK = 'PENDING_STOCK_CHECK';
    case READY_AK_DISPATCH = 'READY_AK_DISPATCH';
    case WAITING_PL_STOCK = 'WAITING_PL_STOCK';
    case PL_SECURING_STOCK = 'PL_SECURING_STOCK';
    case PL_STOCK_AVAILABLE = 'PL_STOCK_AVAILABLE';
    case SENT_TO_AK = 'SENT_TO_AK';
    case RECEIVED_AK = 'RECEIVED_AK';
    case SENT_CUSTOMER = 'SENT_CUSTOMER';
    case READY_PICKUP = 'READY_PICKUP';
    case PICKED_UP = 'PICKED_UP';
    case WAITING_CORE = 'WAITING_CORE';

    public function isTerminalFor(ProcessThreadType $type): bool
    {
        return match ($type) {
            ProcessThreadType::STOCK_REQUEST => in_array($this, [self::SENT_CUSTOMER, self::PICKED_UP], true),
            ProcessThreadType::STOCK_REPLENISHMENT => $this === self::RECEIVED_AK,
            default => false,
        };
    }
}
