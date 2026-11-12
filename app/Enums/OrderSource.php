<?php

namespace App\Enums;

enum OrderSource: string
{
    case ESHOP = 'ESHOP';
    case SYSTEM_BUYOUT = 'SYSTEM_BUYOUT';
}
