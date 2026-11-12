<?php

namespace App\Enums;

enum RebuildFaultCode: string
{
    case OIL_LEAK = 'OIL_LEAK';
    case KNOCKING = 'KNOCKING';
    case FAULTY_UNIT = 'FAULTY_UNIT';
    case UNKNOWN = 'UNKNOWN';
}
