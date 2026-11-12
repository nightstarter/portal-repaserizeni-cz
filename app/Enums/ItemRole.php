<?php

namespace App\Enums;

enum ItemRole: string
{
    case STOCK_ITEM = 'STOCK_ITEM';
    case CUSTOMER_ITEM = 'CUSTOMER_ITEM';
    case CORE_RETURN = 'CORE_RETURN';
    case STOCK_REPLACEMENT = 'STOCK_REPLACEMENT';
    case WARRANTY_ITEM = 'WARRANTY_ITEM';
    case BUYOUT_ITEM = 'BUYOUT_ITEM';
}
