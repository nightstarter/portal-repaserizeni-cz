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

    public function test_diagnostic_uses_compatible_anonymized_schema_inspection(): void
    {
        $this->configureLegacyShopFixture();
        $this->insertLegacyOrder(100, ['customer' => ['firstname' => 'Sensitive', 'lastname' => 'Person', 'email' => 'secret@example.test']]);
        $path = storage_path('app/private/legacy-shop-test-report.json');
        File::delete($path);

        $this->artisan('legacy-shop:diagnose', ['--output' => $path])->assertSuccessful();

        $report = File::get($path);
        self::assertStringContainsString('ps_orders', $report);
        self::assertStringContainsString('server_version', $report);
        self::assertStringContainsString('poptavky_used', $report);
        self::assertStringNotContainsString('generation_expression', $report);
        self::assertStringNotContainsString('Sensitive', $report);
        self::assertStringNotContainsString('secret@example.test', $report);
    }
}
