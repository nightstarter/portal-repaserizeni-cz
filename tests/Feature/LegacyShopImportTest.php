<?php

namespace Tests\Feature;

use App\Contracts\CreatesInitialProcessThreads;
use App\Enums\OrderType;
use App\Enums\OrganizationCode;
use App\Integrations\LegacyShop\LegacySchemaInspector;
use App\Integrations\LegacyShop\LegacyShopConfigurationException;
use App\Integrations\LegacyShop\LegacyShopImportService;
use App\Integrations\LegacyShop\PrestaShop14OrderReader;
use App\Models\IntegrationCheckpoint;
use App\Models\Order;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_reader_builds_canonical_candidate_without_poptavky(): void
    {
        $this->insertLegacyOrder(100);

        $candidate = app(PrestaShop14OrderReader::class)->readOne(100);

        self::assertNotNull($candidate);
        self::assertSame('100', $candidate->sourceOrderId);
        self::assertSame('4101', $candidate->externalOrderNo);
        self::assertSame('REP-100', $candidate->productCode);
        self::assertSame(1, $candidate->steeringItemCount);
        self::assertSame('PERSONAL_PICKUP', $candidate->customerHandover);
        self::assertFalse(app(LegacySchemaInspector::class)->hasTable('ps_poptavky'));
    }

    public function test_deposit_item_is_excluded_from_steering_selection(): void
    {
        $this->insertLegacyOrder(100, [], [
            ['id_order_detail' => 1000, 'product_id' => 91173, 'product_reference' => 'DEPOSIT', 'product_name' => 'Deposit'],
            ['id_order_detail' => 1001, 'product_id' => 100100, 'product_reference' => 'REP-100', 'product_name' => 'Steering'],
        ]);

        $candidate = app(PrestaShop14OrderReader::class)->readOne(100);

        self::assertSame(1, $candidate?->steeringItemCount);
        self::assertSame('REP-100', $candidate?->productCode);
    }

    public function test_missing_steering_item_is_anonymously_reported(): void
    {
        $this->insertLegacyOrder(100, [], [
            ['id_order_detail' => 1000, 'product_id' => 91173, 'product_reference' => 'DEPOSIT', 'product_name' => 'Deposit'],
        ]);

        $result = app(LegacyShopImportService::class)->run(dryRun: true, sourceOrderId: 100);

        self::assertSame(1, $result->errors);
        self::assertContains('STEERING_ITEM_MISSING', $result->validation[0]['issues']);
        self::assertSame(0, Order::query()->count());
    }

    public function test_multiple_steering_items_are_anonymously_reported(): void
    {
        $this->insertLegacyOrder(100, [], [
            ['id_order_detail' => 1000, 'product_id' => 100100, 'product_reference' => 'REP-A', 'product_name' => 'Steering A'],
            ['id_order_detail' => 1001, 'product_id' => 100101, 'product_reference' => 'REP-B', 'product_name' => 'Steering B'],
        ]);

        $result = app(LegacyShopImportService::class)->run(dryRun: true, sourceOrderId: 100);

        self::assertSame(1, $result->errors);
        self::assertContains('STEERING_ITEM_AMBIGUOUS', $result->validation[0]['issues']);
    }

    public function test_ambiguous_payment_is_not_accepted_from_total_alone(): void
    {
        $this->insertLegacyOrder(100, ['history' => ['id_order_state' => 10]]);

        $result = app(LegacyShopImportService::class)->run(dryRun: true, sourceOrderId: 100);

        self::assertSame(1, $result->errors);
        self::assertContains('PAYMENT_AMBIGUOUS', $result->validation[0]['issues']);
    }

    public function test_dry_run_performs_no_portal_writes_even_when_mapping_is_unverified(): void
    {
        $this->insertLegacyOrder(100);
        config()->set('integrations.legacy_shop.mapping.verified', false);

        $result = app(LegacyShopImportService::class)->run(dryRun: true, sourceOrderId: 100);

        self::assertSame(1, $result->wouldImport);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('integration_events', 0);
        $this->assertDatabaseCount('integration_checkpoints', 0);
        self::assertContains('MAPPING_NOT_VERIFIED', $result->validation[0]['warnings']);
    }

    public function test_repeated_writing_import_is_idempotent(): void
    {
        $this->insertLegacyOrder(100);

        $first = app(LegacyShopImportService::class)->run(dryRun: false);
        IntegrationCheckpoint::query()->delete();
        $second = app(LegacyShopImportService::class)->run(dryRun: false);

        self::assertSame(1, $first->imported);
        self::assertSame(1, $second->skipped);
        self::assertSame(1, Order::query()->count());
        self::assertSame('4101', Order::first()->order_no);
        self::assertSame('100', Order::first()->source_order_id);
        self::assertSame(OrderType::STOCK_REBUILD, Order::first()->order_type);
        self::assertSame(3, Order::first()->processThreads()->count());
    }

    public function test_approval_two_maps_custom_branch_but_keeps_repair_subtype_unknown(): void
    {
        $this->insertLegacyOrder(100, ['order' => ['schvaleno' => 2]]);

        $candidate = app(PrestaShop14OrderReader::class)->readOne(100);
        $result = app(LegacyShopImportService::class)->run(dryRun: false);

        self::assertSame('CUSTOM_REBUILD', $candidate?->repairBranch);
        self::assertNull($candidate?->repairSubtype);
        self::assertContains('REPAIR_SUBTYPE_UNCONFIRMED', $candidate?->warnings ?? []);
        self::assertSame(1, $result->imported);
        self::assertSame(OrderType::CUSTOM_REBUILD, Order::first()->order_type);
        self::assertNull(Order::first()->business_snapshot['legacy_repair_subtype']);
        self::assertSame(1, Order::first()->processThreads()->count());
    }

    public function test_approval_zero_is_pending_without_portal_writes_in_dry_run(): void
    {
        $this->insertLegacyOrder(100, ['order' => ['schvaleno' => 0]]);

        $result = app(LegacyShopImportService::class)->run(dryRun: true, sourceOrderId: 100);

        self::assertSame(1, $result->pending);
        self::assertSame(0, $result->errors);
        self::assertSame([], $result->validation[0]['issues']);
        self::assertContains('ORDER_CLASSIFICATION_PENDING', $result->validation[0]['warnings']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('integration_events', 0);
        $this->assertDatabaseCount('integration_checkpoints', 0);
    }

    public function test_pending_approval_is_rechecked_and_imported_after_change_to_stock(): void
    {
        $this->insertLegacyOrder(100, ['order' => ['schvaleno' => 0]]);
        $this->insertLegacyOrder(101, ['order' => ['schvaleno' => 1]]);

        $first = app(LegacyShopImportService::class)->run(dryRun: false);
        $checkpoint = IntegrationCheckpoint::query()->firstOrFail();

        self::assertSame(1, $first->pending);
        self::assertSame(1, $first->imported);
        self::assertSame('101', $checkpoint->last_processed_id);
        self::assertSame(['100'], $checkpoint->metadata['pending_source_order_ids']);
        $this->assertDatabaseMissing('orders', ['source_order_id' => '100']);
        $this->assertDatabaseMissing('integration_events', ['external_event_id' => 'order:100']);

        DB::connection('legacy_shop')->table('ps_orders')->where('id_order', 100)->update(['schvaleno' => 1]);
        $classifications = config('integrations.legacy_shop.mapping.product_classifications');
        $classifications['REP-100']['workflow_scenario'] = 'STOCK_DECIN';
        config()->set('integrations.legacy_shop.mapping.product_classifications', $classifications);

        $second = app(LegacyShopImportService::class)->run(dryRun: false);
        $checkpoint->refresh();

        self::assertSame(1, $second->imported);
        $this->assertDatabaseHas('orders', ['source_order_id' => '100', 'order_type' => OrderType::STOCK_REBUILD->value]);
        self::assertSame([], $checkpoint->metadata['pending_source_order_ids']);
        self::assertSame('101', $checkpoint->last_processed_id);
    }

    public function test_pending_approval_is_rechecked_and_imported_after_change_to_custom(): void
    {
        $this->insertLegacyOrder(100, ['order' => ['schvaleno' => 0]]);
        $this->insertLegacyOrder(101, ['order' => ['schvaleno' => 1]]);
        app(LegacyShopImportService::class)->run(dryRun: false);

        DB::connection('legacy_shop')->table('ps_orders')->where('id_order', 100)->update(['schvaleno' => 2]);

        $result = app(LegacyShopImportService::class)->run(dryRun: false);
        $checkpoint = IntegrationCheckpoint::query()->firstOrFail();

        self::assertSame(1, $result->imported);
        $this->assertDatabaseHas('orders', ['source_order_id' => '100', 'order_type' => OrderType::CUSTOM_REBUILD->value]);
        self::assertSame([], $checkpoint->metadata['pending_source_order_ids']);
        self::assertSame('101', $checkpoint->last_processed_id);
    }

    public function test_null_approval_becomes_visible_integration_error(): void
    {
        $this->insertLegacyOrder(100, ['order' => ['schvaleno' => null]]);

        $result = app(LegacyShopImportService::class)->run(dryRun: false);

        self::assertSame(1, $result->errors);
        $this->assertDatabaseHas('integration_events', [
            'external_event_id' => 'order:100',
            'status' => 'FAILED',
            'error' => 'INCOMPLETE_ORDER:LEGACY_APPROVAL_UNCONFIRMED,ORDER_TYPE_UNCONFIRMED,WORKFLOW_SCENARIO_UNCONFIRMED',
        ]);
    }

    public function test_unknown_approval_becomes_visible_integration_error(): void
    {
        $this->insertLegacyOrder(100, ['order' => ['schvaleno' => 7]]);

        $result = app(LegacyShopImportService::class)->run(dryRun: false);

        self::assertSame(1, $result->errors);
        $this->assertDatabaseHas('integration_events', [
            'external_event_id' => 'order:100',
            'status' => 'FAILED',
        ]);
    }

    public function test_both_start_boundaries_are_enforced(): void
    {
        config()->set('integrations.legacy_shop.minimum_order_id', 200);
        config()->set('integrations.legacy_shop.import_from', '2026-11-01T00:00:00Z');
        $this->insertLegacyOrder(199, ['order' => ['date_add' => '2026-11-10 12:00:00']]);
        $this->insertLegacyOrder(200, ['order' => ['date_add' => '2026-10-31 23:59:59']]);
        $this->insertLegacyOrder(201, ['order' => ['date_add' => '2026-11-01 00:00:00']]);

        $result = app(LegacyShopImportService::class)->run(dryRun: false);

        self::assertSame(1, $result->imported);
        $this->assertDatabaseHas('orders', ['source_order_id' => '201']);
        $this->assertDatabaseMissing('orders', ['source_order_id' => '199']);
        $this->assertDatabaseMissing('orders', ['source_order_id' => '200']);
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

    public function test_missing_start_boundary_is_rejected_for_batch_read(): void
    {
        $this->insertLegacyOrder(100);
        config()->set('integrations.legacy_shop.minimum_order_id', null);
        config()->set('integrations.legacy_shop.import_from', null);

        $this->expectException(LegacyShopConfigurationException::class);
        app(LegacyShopImportService::class)->run(dryRun: true);
    }

    public function test_disabled_import_rejects_writing_run(): void
    {
        $this->insertLegacyOrder(100);
        config()->set('integrations.legacy_shop.enabled', false);

        $this->expectException(LegacyShopConfigurationException::class);
        app(LegacyShopImportService::class)->run(dryRun: false);
    }
}
