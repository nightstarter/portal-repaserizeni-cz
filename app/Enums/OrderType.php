<?php

namespace App\Enums;

enum OrderType: string
{
    case CUSTOM_REBUILD = 'CUSTOM_REBUILD';
    case STOCK_REBUILD = 'STOCK_REBUILD';
    case BUYOUT = 'BUYOUT';
}
