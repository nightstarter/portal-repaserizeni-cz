<?php

namespace Tests\Unit;

use App\Enums\RoutingCode;
use App\Enums\TechnicalType;
use App\Integrations\LegacyShop\LegacyProductClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LegacyProductClassifierTest extends TestCase
{
    #[DataProvider('knownValues')]
    public function test_normalizes_confirmed_omacka_values(
        ?string $raw,
        TechnicalType $technicalType,
        RoutingCode $routing,
    ): void {
        $classifier = new LegacyProductClassifier;

        self::assertSame($technicalType, $classifier->technicalType($raw));
        self::assertSame($routing, $classifier->defaultRouting($technicalType));
    }

    /** @return iterable<string, array{?string, TechnicalType, RoutingCode}> */
    public static function knownValues(): iterable
    {
        yield 'EPS' => ['EPS', TechnicalType::EPS, RoutingCode::ARGO];
        yield 'EPS_H' => ['EPS_H', TechnicalType::EPS, RoutingCode::ARGO];
        yield 'EPS_EL' => ['EPS_EL', TechnicalType::EPS, RoutingCode::ARGO];
        yield 'HYD' => ['HYD', TechnicalType::HYDRAULIC, RoutingCode::ARGO];
        yield 'HYD_H' => ['HYD_H', TechnicalType::HYDRAULIC, RoutingCode::ARGO];
        yield 'HYD_V' => ['HYD_V', TechnicalType::HYDRAULIC, RoutingCode::ARGO];
        yield 'HYD_HV' => ['HYD_HV', TechnicalType::HYDRAULIC, RoutingCode::ARGO];
        yield 'snek' => ['snek', TechnicalType::WORM, RoutingCode::KRAFT];
        yield 'case insensitive' => ['ePs_eL', TechnicalType::EPS, RoutingCode::ARGO];
        yield 'trimmed' => ["  hyd_hv\t", TechnicalType::HYDRAULIC, RoutingCode::ARGO];
        yield 'worm uppercase and whitespace' => [' SNEK ', TechnicalType::WORM, RoutingCode::KRAFT];
    }

    #[DataProvider('unknownValues')]
    public function test_keeps_unconfirmed_omacka_values_unknown(?string $raw): void
    {
        self::assertNull((new LegacyProductClassifier)->technicalType($raw));
    }

    /** @return iterable<string, array{?string}> */
    public static function unknownValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => [" \t "];
        yield 'Mech_el' => ['Mech_el'];
        yield 'Mech_bez' => ['Mech_bez'];
        yield 'unknown' => ['OTHER'];
    }
}
