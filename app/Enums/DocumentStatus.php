<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INVALIDATED = 'INVALIDATED';
}
