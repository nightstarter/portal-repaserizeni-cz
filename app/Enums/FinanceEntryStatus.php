<?php

namespace App\Enums;

enum FinanceEntryStatus: string
{
    case CONFIRMED = 'CONFIRMED';
    case APPROVED = 'APPROVED';
    case SETTLED = 'SETTLED';
    case REVERSED = 'REVERSED';
}
