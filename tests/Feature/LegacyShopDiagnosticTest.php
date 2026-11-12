<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\InteractsWithLegacyShopFixture;
use Tests\TestCase;

class LegacyShopDiagnosticTest extends TestCase
{
    use InteractsWithLegacyShopFixture;
    use RefreshDatabase;

    public function test_diagnostic_creates_an_anonymized_schema_and_mapping_report(): void
    {
        $this->configureLegacyShopFixture();
        $this->insertLegacyOrder(100, [
            'customer_name' => 'Sensitive Fixture Name',
            'customer_email' => 'sensitive@example.test',
            'vin' => 'SENSITIVEVIN123456',
        ]);
        $path = storage_path('app/private/legacy-shop-test-report.json');
        File::delete($path);

        $this->artisan('legacy-shop:diagnose', ['--output' => $path])->assertSuccessful();

        $report = File::get($path);
        self::assertStringContainsString('fixture_orders', $report);
        self::assertStringContainsString('customer_email', $report);
        self::assertStringContainsString('"present": true', $report);
        self::assertStringNotContainsString('Sensitive Fixture Name', $report);
        self::assertStringNotContainsString('sensitive@example.test', $report);
        self::assertStringNotContainsString('SENSITIVEVIN123456', $report);
        self::assertStringNotContainsString('password', strtolower($report));
    }
}
