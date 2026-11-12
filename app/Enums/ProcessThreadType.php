<?php

namespace App\Enums;

enum ProcessThreadType: string
{
    case STOCK_REQUEST = 'STOCK_REQUEST';
    case CUSTOM_REBUILD = 'CUSTOM_REBUILD';
    case CORE_RETURN = 'CORE_RETURN';
    case STOCK_REPLENISHMENT = 'STOCK_REPLENISHMENT';
    case WARRANTY = 'WARRANTY';
    case BUYOUT = 'BUYOUT';
}
