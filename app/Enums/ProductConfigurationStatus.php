<?php

namespace App\Enums;

enum ProductConfigurationStatus: string
{
    case UNVERIFIED = 'UNVERIFIED';
    case VERIFIED = 'VERIFIED';
}
