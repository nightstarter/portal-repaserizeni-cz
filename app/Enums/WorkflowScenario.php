<?php

namespace App\Enums;

enum WorkflowScenario: string
{
    case CUSTOM_REBUILD = 'CUSTOM_REBUILD';
    case PENDING_STOCK_CHECK = 'PENDING_STOCK_CHECK';
    case BUYOUT = 'BUYOUT';
}
