<?php

namespace Tests\Feature;

use App\Contracts\CreatesInitialProcessThreads;
use App\Enums\OrderType;
use App\Enums\OrganizationCode;
use App\Enums\ResponsibleParty;
use App\Enums\RoutingCode;
use App\Integrations\LegacyShop\LegacySchemaInspector;
use App\Integrations\LegacyShop\LegacyShopConfigurationException;
use App\Integrations\LegacyShop\LegacyShopImportService;
use App\Integrations\LegacyShop\PrestaShop14OrderReader;
use App\Models\IntegrationCheckpoint;
use App\Models\LegacyProductConfiguration;
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
        self::assertContains('PAYMENT_NOT_ACCEPTED', $result->validation[0]['issues']);
    }

    public function test_payment_is_accepted_from_historical_state_two_even_after_later_state(): void
    {
        $this->insertLegacyOrder(100);
        DB::connection('legacy_shop')->table('ps_order_history')->insert([
            'id_order_history' => 1001,
            'id_order' => 100,
            'id_order_state' => 10,
            'date_add' => '2026-11-10 12:10:00',
        ]);

        $result = app(LegacyShopImportService::class)->run(dryRun: true, sourceOrderId: 100);

        self::assertSame(0, $result->errors);
        self::assertTrue($result->validation[0]['payment_accepted']);
        self::assertSame(10, $result->validation[0]['latest_state']['id']);
    }

    public function test_unknown_product_requires_visible_configuration(): void
    {
        $this->insertLegacyOrder(100, ['configure_product' => false, 'product' => ['omacka' => 'Mech_el']]);

        $result = app(LegacyShopImportService::class)->run(dryRun: true, sourceOrderId: 100);

        self::assertSame(1, $result->errors);
        self::assertContains('PRODUCT_CONFIGURATION_REQUIRED', $result->validation[0]['issues']);
        self::assertSame('PRODUCT_CONFIGURATION_REQUIRED', $result->validation[0]['product_configuration_status']);
        self::assertSame('PRODUCT_CONFIGURATION_REQUIRED', $result->validation[0]['configuration_queue']);
        self::assertSame('AUTOKLUBY', $result->validation[0]['configuration_queue_owner']);
        self::assertNull($result->validation[0]['technical_type']);
        self::assertNull($result->validation[0]['routing']);
    }

    public function test_unknown_product_creates_visible_autokluby_integration_queue_on_writing_run(): void
    {
        $this->insertLegacyOrder(100, ['configure_product' => false, 'product' => ['omacka' => null]]);

        $result = app(LegacyShopImportService::class)->run(dryRun: false);

        self::assertSame(1, $result->errors);
        $this->assertDatabaseHas('integration_events', [
            'external_event_id' => 'order:100',
            'type' => 'PRODUCT_CONFIGURATION_REQUIRED',
            'status' => 'FAILED',
        ]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_verified_portal_product_registry_overrides_unknown_legacy_value(): void
    {
        $this->insertLegacyOrder(100, ['configure_product' => true, 'product' => ['omacka' => 'Mech_bez']]);

        $result = app(LegacyShopImportService::class)->run(dryRun: true, sourceOrderId: 100);

        self::assertSame(0, $result->errors);
        self::assertSame('EPS', $result->validation[0]['technical_type']);
        self::assertSame('ARGO', $result->validation[0]['routing']);
        self::assertSame('PORTAL_PRODUCT_OVERRIDE', $result->validation[0]['product_classification_source']);
    }

    public function test_order_routing_override_does_not_change_product_default(): void
    {
        $this->insertLegacyOrder(100, ['configure_product' => true]);
        app(LegacyShopImportService::class)->run(dryRun: false);

        Order::query()->firstOrFail()->update(['routing' => RoutingCode::KRAFT]);

        self::assertSame(RoutingCode::KRAFT, Order::query()->firstOrFail()->routing);
        self::assertSame(RoutingCode::ARGO, LegacyProductConfiguration::query()->firstOrFail()->default_routing);
    }

    public function test_carrier_eighteen_maps_to_gls_and_unknown_carrier_is_rejected(): void
    {
        $this->insertLegacyOrder(100, ['order' => ['id_carrier' => 18]]);
        $this->insertLegacyOrder(101, ['order' => ['id_carrier' => 30]]);

        $gls = app(LegacyShopImportService::class)->run(dryRun: true, sourceOrderId: 100);
        $unknown = app(LegacyShopImportService::class)->run(dryRun: true, sourceOrderId: 101);

        self::assertSame('GLS', $gls->validation[0]['handover_type']);
        self::assertNotContains('DELIVERY_MAPPING_REQUIRED', $gls->validation[0]['issues']);
        self::assertNull($unknown->validation[0]['handover_type']);
        self::assertContains('DELIVERY_MAPPING_REQUIRED', $unknown->validation[0]['issues']);
    }

    public function test_missing_pohoda_snapshot_keeps_stock_order_out_of_polish_branch(): void
    {
        $this->insertLegacyOrder(100);

        $result = app(LegacyShopImportService::class)->run(dryRun: false);
        $order = Order::query()->firstOrFail();

        self::assertSame(1, $result->imported);
        self::assertSame('PENDING_STOCK_CHECK', $order->processThreads()->sole()->state);
        self::assertSame(ResponsibleParty::AUTOKLUBY, $order->processThreads()->sole()->responsible_party);
        self::assertFalse($order->processThreads()->where('responsible_party', ResponsibleParty::POLAND)->exists());
    }

    public function test_diagnostic_output_contains_no_personal_data(): void
    {
        $this->insertLegacyOrder(100, [
            'customer' => ['firstname' => 'SecretName', 'email' => 'secret@example.test'],
            'address' => ['address1' => 'Secret Street 123'],
            'vehicle' => ['vin' => 'SECRET-VIN-123'],
        ]);

        $result = app(LegacyShopImportService::class)->run(dryRun: true, sourceOrderId: 100);
        $output = json_encode($result->validation, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('SecretName', $output);
        self::assertStringNotContainsString('secret@example.test', $output);
        self::assertStringNotContainsString('Secret Street 123', $output);
        self::assertStringNotContainsString('SECRET-VIN-123', $output);
        self::assertSame('100', $result->validation[0]['source_order_id']);
        self::assertSame(64, strlen($result->validation[0]['candidate_hash']));
    }

    public function test_dry_run_executes_no_legacy_writes(): void
    {
        $this->insertLegacyOrder(100);
        $legacy = DB::connection('legacy_shop');
        $legacy->flushQueryLog();
        $legacy->enableQueryLog();

        app(LegacyShopImportService::class)->run(dryRun: true, sourceOrderId: 100);

        foreach ($legacy->getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query['query']);
        }
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
        self::assertSame(1, Order::first()->processThreads()->count());
        self::assertSame('PENDING_STOCK_CHECK', Order::first()->processThreads()->first()->state);
    }

    public function test_approval_two_defaults_to_custom_repair_subtype(): void
    {
        $this->insertLegacyOrder(100, ['order' => ['schvaleno' => 2]]);

        $candidate = app(PrestaShop14OrderReader::class)->readOne(100);
        $result = app(LegacyShopImportService::class)->run(dryRun: false);

        self::assertSame('CUSTOM_REBUILD', $candidate?->repairBranch);
        self::assertSame('custom_repair', $candidate?->repairSubtype);
        self::assertSame(1, $result->imported);
        self::assertSame(OrderType::CUSTOM_REBUILD, Order::first()->order_type);
        self::assertSame('custom_repair', Order::first()->business_snapshot['legacy_repair_subtype']);
        self::assertSame(1, Order::first()->processThreads()->count());
    }

    public function test_explicit_catalog_offer_sets_catalog_repair_subtype(): void
    {
        $this->insertLegacyOrder(100, [
            'order' => ['schvaleno' => 2],
            'offers' => [['id_offer' => 500, 'offer_mode' => 'catalog_repair']],
        ]);

        $result = app(LegacyShopImportService::class)->run(dryRun: true, sourceOrderId: 100);

        self::assertSame(0, $result->errors);
        self::assertSame('catalog_repair', $result->validation[0]['repair_subtype']);
    }

    public function test_ambiguous_offer_link_is_visible_error(): void
    {
        $this->insertLegacyOrder(100, [
            'order' => ['schvaleno' => 2],
            'offers' => [
                ['id_offer' => 500, 'offer_mode' => 'catalog_repair'],
                ['id_offer' => 501, 'offer_mode' => 'custom_repair'],
            ],
        ]);

        $result = app(LegacyShopImportService::class)->run(dryRun: true, sourceOrderId: 100);

        self::assertSame(1, $result->errors);
        self::assertContains('REPAIR_SUBTYPE_AMBIGUOUS', $result->validation[0]['issues']);
        self::assertNull($result->validation[0]['repair_subtype']);
    }

    public function test_stock_offer_cannot_silently_become_custom_repair(): void
    {
        $this->insertLegacyOrder(100, [
            'order' => ['schvaleno' => 2],
            'offers' => [['id_offer' => 500, 'offer_mode' => 'stock_product']],
        ]);

        $result = app(LegacyShopImportService::class)->run(dryRun: true, sourceOrderId: 100);

        self::assertContains('REPAIR_BRANCH_OFFER_MISMATCH', $result->validation[0]['issues']);
        self::assertNull($result->validation[0]['repair_subtype']);
    }

    public function test_raw_omacka_is_preserved_in_business_snapshot(): void
    {
        $this->insertLegacyOrder(100, ['product' => ['omacka' => '  hyd_h  ']]);

        app(LegacyShopImportService::class)->run(dryRun: false);

        self::assertSame('  hyd_h  ', Order::query()->firstOrFail()->business_snapshot['legacy_product_omacka_raw']);
        self::assertSame('HYDRAULIC', Order::query()->firstOrFail()->technical_type->value);
        self::assertSame('ARGO', Order::query()->firstOrFail()->routing->value);
    }

    public function test_confirmed_handover_carriers_are_mapped_exactly(): void
    {
        $this->insertLegacyOrder(100, ['order' => ['id_carrier' => 18]]);
        $this->insertLegacyOrder(101, ['order' => ['id_carrier' => 19]]);
        $this->insertLegacyOrder(102, ['order' => ['id_carrier' => 20]]);

        self::assertSame('GLS', app(PrestaShop14OrderReader::class)->readOne(100)?->customerHandover);
        self::assertSame('PERSONAL_PICKUP', app(PrestaShop14OrderReader::class)->readOne(101)?->customerHandover);
        self::assertSame('PERSONAL_PICKUP', app(PrestaShop14OrderReader::class)->readOne(102)?->customerHandover);
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
