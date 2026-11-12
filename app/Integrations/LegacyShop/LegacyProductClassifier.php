<?php

namespace App\Integrations\LegacyShop;

use App\Enums\RoutingCode;
use App\Enums\TechnicalType;

class LegacyProductClassifier
{
    public function technicalType(?string $rawOmacka): ?TechnicalType
    {
        $value = strtoupper(trim((string) $rawOmacka));

        return match ($value) {
            'EPS', 'EPS_H', 'EPS_EL' => TechnicalType::EPS,
            'HYD', 'HYD_H', 'HYD_V', 'HYD_HV' => TechnicalType::HYDRAULIC,
            'SNEK' => TechnicalType::WORM,
            default => null,
        };
    }

    public function defaultRouting(TechnicalType $technicalType): RoutingCode
    {
        return match ($technicalType) {
            TechnicalType::WORM => RoutingCode::KRAFT,
            TechnicalType::HYDRAULIC, TechnicalType::EPS => RoutingCode::ARGO,
        };
    }
}
