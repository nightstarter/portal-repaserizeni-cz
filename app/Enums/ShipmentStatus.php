<?php

namespace App\Enums;

enum ShipmentStatus: string
{
    case PREPARED = 'PREPARED';
    case SENT = 'SENT';
    case DELIVERED = 'DELIVERED';
    case TRANSPORT_ERROR = 'TRANSPORT_ERROR';
}
