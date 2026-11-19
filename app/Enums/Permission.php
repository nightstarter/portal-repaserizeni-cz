<?php

namespace App\Enums;

enum Permission: string
{
    case BUYBACK_FINAL_PRICE_APPROVE = 'BUYBACK_FINAL_PRICE_APPROVE';
    case BUYBACK_PAYMENT_CONFIRM = 'BUYBACK_PAYMENT_CONFIRM';
}
