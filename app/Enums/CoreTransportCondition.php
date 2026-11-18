<?php

namespace App\Enums;

enum CoreTransportCondition: string
{
    case NO_VISIBLE_DAMAGE = 'NO_VISIBLE_DAMAGE';
    case PACKAGING_DAMAGED = 'PACKAGING_DAMAGED';
    case ITEM_DAMAGED = 'ITEM_DAMAGED';
}
