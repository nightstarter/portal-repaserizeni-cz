<?php

namespace App\Enums;

enum CoreReturnState: string
{
    case WAITING_CORE = 'WAITING_CORE';
    case CORE_RECEIVED_AK = 'CORE_RECEIVED_AK';
    case SENT_TO_PL = 'SENT_TO_PL';
    case CORE_INSPECTION = 'CORE_INSPECTION';
    case DEPOSIT_FULL_APPROVED = 'DEPOSIT_FULL_APPROVED';
    case DEPOSIT_REDUCED_APPROVED = 'DEPOSIT_REDUCED_APPROVED';
    case CORE_SETTLED_NO_DEPOSIT = 'CORE_SETTLED_NO_DEPOSIT';

    public function completed(): bool
    {
        return $this === self::CORE_SETTLED_NO_DEPOSIT;
    }
}
