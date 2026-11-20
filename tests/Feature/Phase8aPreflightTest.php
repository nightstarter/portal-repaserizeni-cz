<?php

namespace Tests\Feature;

use App\Enums\OrganizationCode;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\StockSnapshotRun;
use App\Models\User;
use App\Operations\PreflightCheck;
use App\Operations\ProductionPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BackupFixture;
use Tests\TestCase;

class Phase8aPreflightTest extends TestCase
{
    use RefreshDatabase;

    private ?BackupFixture $backupFixture = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn (): string => 'production');
        config([
            'app.debug' => false,
            'app.url' => 'https://portal.example.test',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'cache.default' => 'array',
            'operations.preflight.disk_free_bytes_override' => 10 * 1024 * 1024 * 1024,
            'operations.preflight.minimum_free_disk_megabytes' => 1,
            'operations.product_repricing' => 'DEFERRED_EXTERNAL_SYSTEM',
            'integrations.legacy_shop.enabled' => false,
            'discussion_notifications.enabled' => false,
            'buyback.customer_email_enabled' => false,
            'integrations.pohoda_bridge.allowed_command_types' => [],
            'integrations.pohoda_bridge.warehouses' => ['DECIN'],
            'integrations.pohoda_bridge.product_prefixes' => ['RR'],
        ]);
        $this->backupFixture = BackupFixture::create([], [
            'off_server' => ['confirmed' => true, 'confirmed_at_utc' => now()->utc()->format('Y-m-d\TH:i:s\Z')],
            'restore_test' => ['status' => 'SUCCESS', 'completed_at_utc' => now()->utc()->format('Y-m-d\TH:i:s\Z')],
        ]);
        config(['backups.latest_manifest' => $this->backupFixture->root()]);
    }

    protected function tearDown(): void
    {
        $this->backupFixture?->cleanup();
        parent::tearDown();
    }

    public function test_cli_json_is_sanitized_and_warn_only_returns_zero(): void
    {
        config([
            'integrations.pohoda_bridge.secret' => 'never-print-bridge-secret',
            'database.connections.sqlite.password' => 'never-print-db-secret',
            'mail.mailers.smtp.password' => 'never-print-smtp-secret',
        ]);

        $exit = Artisan::call('ops:preflight', ['--json' => true]);
        $output = Artisan::output();
        $json = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('WARN', $json['status']);
        $this->assertNotEmpty($json['checks']);
        $this->assertStringNotContainsString('never-print', $output);
        $this->assertStringNotContainsString('password', strtolower($output));
        $this->assertContains('scheduler', array_column($json['checks'], 'id'));
    }

    public function test_errors_produce_nonzero_exit_and_disabled_smtp_is_not_an_error(): void
    {
        config([
            'integrations.pohoda_bridge.allowed_command_types' => ['NOOP'],
            'operations.preflight.disk_free_bytes_override' => 1,
            'filesystems.disks.local.root' => storage_path('missing-private-storage'),
        ]);

        $exit = Artisan::call('ops:preflight', ['--json' => true]);
        $checks = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'])->keyBy('id');

        $this->assertSame(1, $exit);
        $this->assertSame(PreflightCheck::ERROR, $checks['bridge_command_whitelist']['status']);
        $this->assertSame(PreflightCheck::ERROR, $checks['disk_space']['status']);
        $this->assertSame(PreflightCheck::ERROR, $checks['private_storage']['status']);
        $this->assertSame(PreflightCheck::OK, $checks['smtp']['status']);
        $this->assertFalse($checks['smtp']['context']['feature_enabled']);
    }

    public function test_stale_heartbeat_and_snapshot_are_warn_and_unmapped_is_not_error(): void
    {
        $this->insertHeartbeat(now()->subHour());
        $runId = $this->insertRun(StockSnapshotRun::COMPLETE, 1, 0, now()->subHour());

        $checks = collect(app(ProductionPreflight::class)->run())->keyBy('id');

        $this->assertSame(PreflightCheck::WARN, $checks['pohoda_heartbeat']->status);
        $this->assertSame(PreflightCheck::WARN, $checks['pohoda_snapshot']->status);
        $this->assertSame($runId, DB::table('stock_snapshot_runs')->value('id'));
    }

    public function test_fresh_known_partial_snapshot_with_two_duplicates_is_usable(): void
    {
        $this->insertHeartbeat(now()->subMinute());
        $runId = $this->insertRun(StockSnapshotRun::PARTIAL, 2482, 2, now()->subMinute());
        $batchId = DB::table('stock_snapshot_batches')->insertGetId([
            'stock_snapshot_run_id' => $runId,
            'batch_id' => (string) Str::uuid(),
            'chunk_index' => 0,
            'chunk_count' => 1,
            'final_chunk' => true,
            'key_id' => 'test-key',
            'warehouse_code' => 'DECIN',
            'source_generated_at' => now()->subMinute(),
            'received_at' => now(),
            'status' => 'PARTIAL',
            'accepted_count' => 2482,
            'rejected_count' => 2,
            'payload_hash' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([0, 1] as $index) {
            DB::table('stock_snapshot_items')->insert([
                'stock_snapshot_batch_id' => $batchId,
                'item_index' => $index,
                'product_reference' => 'RR-DUP-'.$index,
                'source_fingerprint' => hash('sha256', (string) $index),
                'accepted' => false,
                'rejection_code' => 'DUPLICATE_PRODUCT_REFERENCE',
                'created_at' => now(),
            ]);
        }

        $check = collect(app(ProductionPreflight::class)->run())->firstWhere('id', 'pohoda_snapshot');

        $this->assertSame(PreflightCheck::OK, $check->status);
        $this->assertTrue($check->context['known_partial']);
        $this->assertStringContainsString('2482 + 2', $check->message);
    }

    public function test_admin_page_is_404_for_every_non_admin_role_and_query_count_is_constant(): void
    {
        $ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY]);
        $pl = Organization::factory()->create(['code' => OrganizationCode::POLAND]);
        foreach ([
            [$ak, UserRole::AK_OPERATOR],
            [$ak, UserRole::READ_ONLY],
            [$pl, UserRole::PL_OPERATOR],
        ] as [$organization, $role]) {
            $user = User::factory()->create(['organization_id' => $organization->id, 'role' => $role]);
            $this->actingAs($user)->get(route('admin.operations.preflight'))->assertNotFound();
        }
        $admin = User::factory()->create(['organization_id' => $ak->id, 'role' => UserRole::AK_ADMIN]);
        $this->actingAs($admin);
        $small = $this->selectCountForPreflightPage();

        for ($i = 0; $i < 50; $i++) {
            $this->insertHeartbeat(now()->subMinutes($i));
        }
        $large = $this->selectCountForPreflightPage();
        $html = $this->get(route('admin.operations.preflight'))->assertOk()->getContent();

        $this->assertLessThanOrEqual($small + 1, $large);
        $this->assertLessThanOrEqual(24, $large);
        $this->assertStringContainsString('Produkční připravenost', $html);
        $this->assertStringNotContainsString('DB_PASSWORD', $html);
        $this->assertStringNotContainsString('POHODA_BRIDGE_SECRET', $html);
    }

    private function insertHeartbeat($occurredAt): void
    {
        DB::table('bridge_heartbeats')->insert([
            'event_id' => (string) Str::uuid(), 'key_id' => 'test-key', 'connector_id' => 'test-connector',
            'connector_version' => '1.0.0', 'occurred_at' => $occurredAt, 'last_pohoda_read_at' => $occurredAt,
            'last_snapshot_at' => $occurredAt, 'last_command_poll_at' => $occurredAt, 'status' => 'OK',
            'error_codes' => '[]', 'payload_hash' => str_repeat('a', 64), 'received_at' => $occurredAt,
        ]);
    }

    private function insertRun(string $status, int $accepted, int $rejected, $time): int
    {
        return DB::table('stock_snapshot_runs')->insertGetId([
            'run_id' => (string) Str::uuid(), 'warehouse_code' => 'DECIN', 'source_generated_at' => $time,
            'chunk_count' => 1, 'received_chunks' => 1, 'accepted_count' => $accepted, 'rejected_count' => $rejected,
            'dataset_fingerprint' => str_repeat('b', 64), 'status' => $status, 'expires_at' => now()->addHour(),
            'completed_at' => $time, 'created_at' => $time, 'updated_at' => $time,
        ]);
    }

    private function selectCountForPreflightPage(): int
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $queries[] = $query->sql;
            }
        });
        $this->get(route('admin.operations.preflight'))->assertOk();

        return count($queries);
    }
}
