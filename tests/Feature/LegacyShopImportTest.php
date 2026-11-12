<?php

namespace Tests\Feature;

use App\Contracts\CreatesInitialProcessThreads;
use App\Enums\OrganizationCode;
use App\Integrations\LegacyShop\LegacyShopConfigurationException;
use App\Integrations\LegacyShop\LegacyShopImportService;
use App\Models\IntegrationCheckpoint;
use App\Models\Order;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Support\InteractsWithLegacyShopFixture;
use Tests\TestCase;

class LegacyShopImportTest extends TestCase
{
    use InteractsWithLegacyShopFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureLegacyShopFixture();
        Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
    }

    public function test_repeated_import_does_not_create_a_duplicate(): void
    {
        $this->insertLegacyOrder(100);

        $first = app(LegacyShopImportService::class)->run(dryRun: false);
        IntegrationCheckpoint::query()->delete();
        $second = app(LegacyShopImportService::class)->run(dryRun: false);

        self::assertSame(1, $first->imported);
        self::assertSame(1, $second->skipped);
        self::assertSame(1, Order::query()->count());
        self::assertSame(1, Order::first()->processThreads()->count());
    }

    public function test_dry_run_performs_no_portal_writes(): void
    {
        $this->insertLegacyOrder(100);

        $result = app(LegacyShopImportService::class)->run(dryRun: true);

        self::assertSame(1, $result->wouldImport);
        self::assertSame(0, Order::query()->count());
        $this->assertDatabaseCount('integration_events', 0);
        $this->assertDatabaseCount('integration_checkpoints', 0);
    }

    public function test_both_start_boundaries_are_enforced(): void
    {
        $this->configureLegacyShopFixture([
            'minimum_order_id' => 200,
            'import_from' => '2026-11-01T00:00:00Z',
        ]);
        $this->insertLegacyOrder(199, ['legacy_created_at' => '2026-11-10 12:00:00']);
        $this->insertLegacyOrder(200, ['legacy_created_at' => '2026-10-31 23:59:59']);
        $this->insertLegacyOrder(201, ['legacy_created_at' => '2026-11-01 00:00:00']);

        $result = app(LegacyShopImportService::class)->run(dryRun: false);

        self::assertSame(1, $result->imported);
        $this->assertDatabaseHas('orders', ['source_order_id' => '201']);
        $this->assertDatabaseMissing('orders', ['source_order_id' => '199']);
        $this->assertDatabaseMissing('orders', ['source_order_id' => '200']);
    }

    public function test_missing_start_boundary_is_rejected_before_reading(): void
    {
        $this->configureLegacyShopFixture(['minimum_order_id' => null, 'import_from' => null]);
        $this->insertLegacyOrder(100);

        $this->expectException(LegacyShopConfigurationException::class);
        app(LegacyShopImportService::class)->run(dryRun: true);
    }

    public function test_incomplete_order_becomes_visible_integration_error(): void
    {
        $this->insertLegacyOrder(100, ['product_code' => null]);

        $result = app(LegacyShopImportService::class)->run(dryRun: false);

        self::assertSame(1, $result->errors);
        self::assertSame(0, Order::query()->count());
        $this->assertDatabaseHas('integration_events', [
            'integration' => LegacyShopImportService::INTEGRATION,
            'external_event_id' => 'order:100',
            'status' => 'FAILED',
            'error' => 'INCOMPLETE_ORDER:product_code',
        ]);
        $this->assertDatabaseCount('integration_checkpoints', 0);
    }

    public function test_order_customer_and_threads_roll_back_together(): void
    {
        $this->insertLegacyOrder(100);
        $creator = Mockery::mock(CreatesInitialProcessThreads::class);
        $creator->shouldReceive('createFor')->once()->andThrow(new RuntimeException('fixture failure'));
        $this->app->instance(CreatesInitialProcessThreads::class, $creator);

        $result = app(LegacyShopImportService::class)->run(dryRun: false);

        self::assertSame(1, $result->errors);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_customers', 0);
        $this->assertDatabaseCount('process_threads', 0);
        $this->assertDatabaseHas('integration_events', ['external_event_id' => 'order:100', 'status' => 'FAILED']);
        $this->assertDatabaseCount('integration_checkpoints', 0);
    }

    public function test_disabled_import_rejects_a_writing_run(): void
    {
        config()->set('integrations.legacy_shop.enabled', false);
        $this->insertLegacyOrder(100);

        $this->expectException(LegacyShopConfigurationException::class);
        app(LegacyShopImportService::class)->run(dryRun: false);
    }
}
