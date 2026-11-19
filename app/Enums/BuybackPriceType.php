<?php

namespace App\Enums;

enum BuybackPriceType: string
{
    case INTERNAL_AK = 'INTERNAL_AK';
    case POLAND_OFFER = 'POLAND_OFFER';
    case FINAL_CUSTOMER = 'FINAL_CUSTOMER';
}
