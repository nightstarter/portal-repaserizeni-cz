<?php

namespace App\Enums;

enum WorkflowScenario: string
{
    case CUSTOM_REBUILD = 'CUSTOM_REBUILD';
    case PENDING_STOCK_CHECK = 'PENDING_STOCK_CHECK';
    case STOCK_DECIN = 'STOCK_DECIN';
    case STOCK_POLAND = 'STOCK_POLAND';
    case BUYOUT = 'BUYOUT';
}
