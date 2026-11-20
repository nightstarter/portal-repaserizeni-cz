<?php

namespace Tests\Feature;

use App\Contracts\ResolvesStockAvailability;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\StockAvailabilityStatus;
use App\Enums\UserRole;
use App\Integrations\Pohoda\BridgeCanonicalRequest;
use App\Models\BridgeCommand;
use App\Models\BridgeCommandResult;
use App\Models\BridgeHeartbeat;
use App\Models\BridgeNonce;
use App\Models\LegacyProductConfiguration;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\StockSnapshotBatch;
use App\Models\StockSnapshotConfirmation;
use App\Models\StockSnapshotItem;
use App\Models\StockSnapshotRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase7aPohodaBridgeTest extends TestCase
{
    use RefreshDatabase;

    private string $currentSecret;

    private string $previousSecret;

    private Organization $ak;

    private Organization $pl;

    private User $admin;

    private User $readOnly;

    private User $poland;

    protected function setUp(): void
    {
        parent::setUp();
        $this->currentSecret = str_repeat('A', 32);
        $this->previousSecret = str_repeat('B', 32);
        config([
            'integrations.pohoda_bridge.enabled' => true,
            'integrations.pohoda_bridge.key_id' => 'current-test-key',
            'integrations.pohoda_bridge.secret' => base64_encode($this->currentSecret),
            'integrations.pohoda_bridge.previous_key_id' => 'previous-test-key',
            'integrations.pohoda_bridge.previous_secret' => base64_encode($this->previousSecret),
            'integrations.pohoda_bridge.timestamp_tolerance' => 300,
            'integrations.pohoda_bridge.stale_minutes' => 30,
            'integrations.pohoda_bridge.max_batch_items' => 500,
            'integrations.pohoda_bridge.max_body_bytes' => 2_097_152,
            'integrations.pohoda_bridge.product_prefixes' => ['RR'],
            'integrations.pohoda_bridge.warehouses' => ['DECIN'],
            'integrations.pohoda_bridge.ip_allowlist' => [],
            'integrations.pohoda_bridge.rate_limit_per_minute' => 1000,
            'integrations.pohoda_bridge.command_lease_minutes' => 5,
            'integrations.pohoda_bridge.allowed_command_types' => ['NOOP'],
        ]);
        $this->ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY]);
        $this->pl = Organization::factory()->create(['code' => OrganizationCode::POLAND]);
        $this->admin = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::AK_ADMIN]);
        $this->readOnly = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::READ_ONLY]);
        $this->poland = User::factory()->create(['organization_id' => $this->pl->id, 'role' => UserRole::PL_OPERATOR]);
    }

    public function test_hmac_accepts_exact_raw_body_query_order_and_rotated_key(): void
    {
        $body = json_encode($this->heartbeat(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->signed('POST', '/api/internal/v1/heartbeat', $body)->assertAccepted();
        $this->signed('GET', '/api/internal/v1/commands?limit=1&connector_id=connector-a', '', queryForSignature: ['connector_id' => 'connector-a', 'limit' => '1'])
            ->assertOk()->assertJson(['commands' => []]);
        $previous = $this->heartbeat(['event_id' => (string) Str::uuid()]);
        $this->signed('POST', '/api/internal/v1/heartbeat', json_encode($previous, JSON_THROW_ON_ERROR), keyId: 'previous-test-key', secret: $this->previousSecret)
            ->assertAccepted();
        self::assertSame(2, BridgeHeartbeat::query()->count());
    }

    public function test_bad_unknown_expired_future_replayed_and_raw_body_signatures_are_rejected_generically(): void
    {
        $body = json_encode($this->heartbeat(), JSON_THROW_ON_ERROR);
        $this->signed('POST', '/api/internal/v1/heartbeat', $body, signature: str_repeat('0', 64), userAgent: 'SECRET-IN-HEADER')->assertUnauthorized()
            ->assertExactJson(['error' => ['code' => 'BRIDGE_AUTH_FAILED']]);
        $this->signed('POST', '/api/internal/v1/heartbeat', $body, keyId: 'unknown')->assertUnauthorized();
        $this->signed('POST', '/api/internal/v1/heartbeat', $body, timestamp: now()->subSeconds(301)->timestamp)->assertUnauthorized();
        $this->signed('POST', '/api/internal/v1/heartbeat', $body, timestamp: now()->addSeconds(301)->timestamp)->assertUnauthorized();

        $nonce = 'unique_nonce_for_replay_123456';
        $valid = $this->heartbeat(['event_id' => (string) Str::uuid()]);
        $validBody = json_encode($valid, JSON_THROW_ON_ERROR);
        $this->signed('POST', '/api/internal/v1/heartbeat', $validBody, nonce: $nonce)->assertAccepted();
        $this->signed('POST', '/api/internal/v1/heartbeat', $validBody, nonce: $nonce)->assertUnauthorized();

        $otherBody = json_encode($this->heartbeat(['event_id' => (string) Str::uuid()]), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $this->signed('POST', '/api/internal/v1/heartbeat', $otherBody, signedBody: $validBody)->assertUnauthorized();
        self::assertSame(1, BridgeNonce::query()->where('nonce', $nonce)->count());
        self::assertGreaterThanOrEqual(5, DB::table('audit_log')->where('action', 'POHODA_BRIDGE_AUTH_REJECTED')->count());
        self::assertFalse(DB::table('audit_log')->where('user_agent', 'LIKE', '%SECRET-IN-HEADER%')->exists());
    }

    public function test_signed_bridge_request_over_plain_http_is_rejected_before_nonce_is_stored(): void
    {
        $body = json_encode($this->heartbeat(), JSON_THROW_ON_ERROR);

        $this->signed('POST', '/api/internal/v1/heartbeat', $body, secure: false)
            ->assertUnauthorized()
            ->assertExactJson(['error' => ['code' => 'BRIDGE_AUTH_FAILED']]);

        self::assertSame(0, BridgeNonce::query()->count());
        self::assertSame(0, BridgeHeartbeat::query()->count());
    }

    public function test_disabled_bridge_and_ip_allowlist_write_nothing(): void
    {
        config(['integrations.pohoda_bridge.enabled' => false]);
        $body = json_encode($this->heartbeat(), JSON_THROW_ON_ERROR);
        $this->signed('POST', '/api/internal/v1/heartbeat', $body)->assertStatus(503);
        self::assertSame(0, BridgeNonce::query()->count());
        self::assertSame(0, DB::table('audit_log')->count());

        config(['integrations.pohoda_bridge.enabled' => true, 'integrations.pohoda_bridge.ip_allowlist' => ['192.0.2.10']]);
        $this->signed('POST', '/api/internal/v1/heartbeat', $body, ip: '127.0.0.1')->assertUnauthorized();
        self::assertSame(0, BridgeNonce::query()->count());
    }

    public function test_invalid_authentication_is_rate_limited(): void
    {
        config(['integrations.pohoda_bridge.rate_limit_per_minute' => 1]);
        $body = json_encode($this->heartbeat(), JSON_THROW_ON_ERROR);
        $this->signed('POST', '/api/internal/v1/heartbeat', $body, signature: str_repeat('0', 64))->assertUnauthorized();
        $this->signed('POST', '/api/internal/v1/heartbeat', $body, keyId: 'attacker-varied-key')->assertTooManyRequests();
    }

    public function test_snapshot_accepts_mapped_and_unmapped_rr_products_and_rejects_invalid_items(): void
    {
        $this->product('RRKNOWN-1');
        $payload = $this->snapshot([
            ['product_reference' => 'RRKNOWN-1', 'available_quantity' => 3, 'reserved_quantity' => 1, 'source_row_version' => 'v1'],
            ['product_reference' => 'RRUNKNOWN-2', 'available_quantity' => 5, 'reserved_quantity' => 2, 'source_row_version' => 'v2'],
            ['product_reference' => 'RRKNOWN-1', 'available_quantity' => -1, 'source_row_version' => 'v3'],
            ['product_reference' => 'RRFRACTION', 'available_quantity' => 1.5, 'source_row_version' => 'v4'],
            ['product_reference' => 'RREXTRA', 'available_quantity' => 1, 'source_row_version' => 'v5', 'unsupported' => true],
        ]);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $response = $this->signed('POST', '/api/internal/v1/stock-snapshots', $body)->assertAccepted()
            ->assertJson(['status' => 'PARTIAL', 'accepted_count' => 2, 'rejected_count' => 3, 'idempotent_replay' => false]);
        self::assertSame(['ACCEPTED', 'ACCEPTED', 'REJECTED', 'REJECTED', 'REJECTED'], collect($response->json('items'))->pluck('status')->all());
        self::assertSame(['MAPPED', 'UNMAPPED', null, null, null], collect($response->json('items'))->pluck('mapping_status')->all());
        self::assertSame(['INVALID_ITEM', 'INVALID_ITEM', 'INVALID_ITEM'], collect($response->json('items'))->pluck('code')->filter()->values()->all());
        $unmapped = StockSnapshotItem::query()->where('product_reference', 'RRUNKNOWN-2')->firstOrFail();
        self::assertNull($unmapped->product_configuration_id);
        self::assertSame(5, $unmapped->available_quantity);
        self::assertSame(2, $unmapped->reserved_quantity);
        self::assertSame('v2', $unmapped->source_row_version);
        self::assertNotNull($unmapped->source_fingerprint);
        self::assertFalse(LegacyProductConfiguration::query()->where('product_reference', 'RRUNKNOWN-2')->exists());
        $this->signed('POST', '/api/internal/v1/stock-snapshots', $body)->assertAccepted()->assertJson(['idempotent_replay' => true]);
        self::assertSame(1, StockSnapshotBatch::query()->count());
        self::assertSame(5, StockSnapshotItem::query()->count());

        $changed = $payload;
        $changed['items'][1]['available_quantity'] = 6;
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($changed, JSON_THROW_ON_ERROR))->assertConflict();
        self::assertSame(1, StockSnapshotBatch::query()->count());
    }

    public function test_snapshot_item_and_raw_body_limits_are_enforced(): void
    {
        config(['integrations.pohoda_bridge.max_batch_items' => 1]);
        $payload = $this->snapshot([
            ['product_reference' => 'RRONE', 'available_quantity' => 1, 'source_row_version' => '1'],
            ['product_reference' => 'RRTWO', 'available_quantity' => 2, 'source_row_version' => '2'],
        ]);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($payload, JSON_THROW_ON_ERROR))->assertUnprocessable();
        self::assertSame(0, StockSnapshotBatch::query()->count());

        config(['integrations.pohoda_bridge.max_body_bytes' => 1024]);
        $oversized = str_repeat('x', 1025);
        $this->signed('POST', '/api/internal/v1/heartbeat', $oversized)->assertStatus(413)
            ->assertExactJson(['error' => ['code' => 'REQUEST_TOO_LARGE']]);
    }

    public function test_server_prefix_and_warehouse_allowlists_are_fail_closed(): void
    {
        $payload = $this->snapshot([
            ['product_reference' => 'OTHER-1', 'available_quantity' => 1, 'source_row_version' => '1'],
            ['product_reference' => 'rrLOWER', 'available_quantity' => 1, 'source_row_version' => '2'],
            ['product_reference' => ' RRSPACE', 'available_quantity' => 1, 'source_row_version' => '3'],
            ['product_reference' => 'RR%WILDCARD', 'available_quantity' => 1, 'source_row_version' => '4'],
        ]);
        $response = $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($payload, JSON_THROW_ON_ERROR))
            ->assertAccepted()->assertJson(['accepted_count' => 0, 'rejected_count' => 4, 'run_status' => StockSnapshotRun::PARTIAL]);
        self::assertSame(
            ['PRODUCT_REFERENCE_NOT_ALLOWED', 'PRODUCT_REFERENCE_NOT_ALLOWED', 'INVALID_ITEM', 'INVALID_ITEM'],
            collect($response->json('items'))->pluck('code')->all(),
        );

        $wrongWarehouse = $this->snapshot([], ['warehouse_code' => 'OTHER']);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($wrongWarehouse, JSON_THROW_ON_ERROR))
            ->assertUnprocessable();

        $requestControlledPrefix = $this->snapshot([]);
        $requestControlledPrefix['product_prefix'] = 'OTHER';
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($requestControlledPrefix, JSON_THROW_ON_ERROR))
            ->assertUnprocessable();

        config(['integrations.pohoda_bridge.product_prefixes' => ['%']]);
        $unsafeConfig = $this->snapshot([
            ['product_reference' => 'RRVALID', 'available_quantity' => 1, 'source_row_version' => '5'],
        ]);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($unsafeConfig, JSON_THROW_ON_ERROR))
            ->assertUnprocessable();
        self::assertSame(1, StockSnapshotRun::query()->count());
    }

    public function test_duplicate_unmapped_reference_is_rejected_within_one_run(): void
    {
        $runId = (string) Str::uuid();
        $first = $this->snapshot([
            ['product_reference' => 'RRDUPLICATE', 'available_quantity' => 4, 'source_row_version' => 'a'],
        ], [
            'snapshot_run_id' => $runId, 'chunk_index' => 0, 'chunk_count' => 2, 'final_chunk' => false,
        ]);
        $second = $this->snapshot([
            ['product_reference' => 'RRDUPLICATE', 'available_quantity' => 5, 'source_row_version' => 'b'],
        ], [
            'snapshot_run_id' => $runId, 'chunk_index' => 1, 'chunk_count' => 2, 'final_chunk' => true,
            'source_generated_at' => $first['source_generated_at'],
        ]);

        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($first, JSON_THROW_ON_ERROR))
            ->assertAccepted()->assertJsonPath('items.0.mapping_status', 'UNMAPPED');
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($second, JSON_THROW_ON_ERROR))
            ->assertAccepted()
            ->assertJsonPath('items.0.status', 'REJECTED')
            ->assertJsonPath('items.0.code', 'DUPLICATE_PRODUCT_REFERENCE')
            ->assertJsonPath('run_status', StockSnapshotRun::PARTIAL);
        self::assertSame(1, StockSnapshotItem::query()->where('product_reference', 'RRDUPLICATE')->where('accepted', true)->count());
    }

    public function test_newer_snapshot_wins_and_stale_snapshot_is_not_conclusive(): void
    {
        Carbon::setTestNow('2026-11-19 12:00:00 UTC');
        $this->product('RRSTOCK-1');
        $order = Order::factory()->create(['product_code' => 'RRSTOCK-1']);
        $newer = $this->snapshot([
            ['product_reference' => 'RRSTOCK-1', 'available_quantity' => 0, 'source_row_version' => 'new'],
        ], ['source_generated_at' => '2026-11-19T11:55:00Z']);
        $older = $this->snapshot([
            ['product_reference' => 'RRSTOCK-1', 'available_quantity' => 9, 'source_row_version' => 'old'],
        ], ['source_generated_at' => '2026-11-19T11:50:00Z']);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($newer, JSON_THROW_ON_ERROR))->assertAccepted();
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($older, JSON_THROW_ON_ERROR))->assertAccepted();
        self::assertSame(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK, app(ResolvesStockAvailability::class)->resolve($order)->status);

        Carbon::setTestNow('2026-11-19 12:31:00 UTC');
        self::assertSame(StockAvailabilityStatus::STALE, app(ResolvesStockAvailability::class)->resolve($order)->status);
    }

    public function test_unmapped_rr_snapshot_resolves_stock_and_later_mapping_does_not_rewrite_history(): void
    {
        Carbon::setTestNow('2026-11-20 12:00:00 UTC');
        $order = Order::factory()->create(['product_code' => 'RRUNMAPPED-STOCK']);
        $available = $this->snapshot([
            ['product_reference' => 'RRUNMAPPED-STOCK', 'available_quantity' => 7, 'reserved_quantity' => 1, 'source_row_version' => 'u1'],
        ], ['source_generated_at' => '2026-11-20T11:59:00Z']);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($available, JSON_THROW_ON_ERROR))
            ->assertAccepted()->assertJsonPath('items.0.mapping_status', 'UNMAPPED');
        self::assertSame(StockAvailabilityStatus::AVAILABLE_IN_AK, app(ResolvesStockAvailability::class)->resolve($order)->status);

        $historical = StockSnapshotItem::query()->where('product_reference', 'RRUNMAPPED-STOCK')->firstOrFail();
        self::assertNull($historical->product_configuration_id);
        $this->product('RRUNMAPPED-STOCK');
        self::assertNull($historical->fresh()->product_configuration_id);
        self::assertSame(StockAvailabilityStatus::AVAILABLE_IN_AK, app(ResolvesStockAvailability::class)->resolve($order)->status);

        $zero = $this->snapshot([
            ['product_reference' => 'RRUNMAPPED-STOCK', 'available_quantity' => 0, 'source_row_version' => 'u2'],
        ], ['source_generated_at' => '2026-11-20T12:00:00Z']);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($zero, JSON_THROW_ON_ERROR))->assertAccepted();
        self::assertSame(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK, app(ResolvesStockAvailability::class)->resolve($order)->status);

        Carbon::setTestNow('2026-11-20 12:31:00 UTC');
        self::assertSame(StockAvailabilityStatus::STALE, app(ResolvesStockAvailability::class)->resolve($order)->status);
    }

    public function test_partial_run_missing_reference_is_unknown_even_with_unmapped_items(): void
    {
        Carbon::setTestNow('2026-11-20 13:00:00 UTC');
        $order = Order::factory()->create(['product_code' => 'RRMISSING']);
        $partial = $this->snapshot([
            ['product_reference' => 'RROTHER', 'available_quantity' => 2, 'source_row_version' => 'p1'],
            ['product_reference' => 'NOT-RR', 'available_quantity' => 1, 'source_row_version' => 'p2'],
        ], ['source_generated_at' => '2026-11-20T12:59:00Z']);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($partial, JSON_THROW_ON_ERROR))
            ->assertAccepted()->assertJson(['run_status' => StockSnapshotRun::PARTIAL]);
        self::assertSame(StockAvailabilityStatus::UNKNOWN, app(ResolvesStockAvailability::class)->resolve($order)->status);
    }

    public function test_chunked_snapshot_is_invisible_until_complete_and_missing_run_expires(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00 UTC');
        $this->product('RRCHUNK-1');
        $this->product('RRCHUNK-2');
        $order = Order::factory()->create(['product_code' => 'RRCHUNK-1']);

        $baseline = $this->snapshot([
            ['product_reference' => 'RRCHUNK-1', 'available_quantity' => 0, 'source_row_version' => 'base'],
        ], ['source_generated_at' => '2026-11-20T09:50:00Z']);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($baseline, JSON_THROW_ON_ERROR))->assertAccepted();
        self::assertSame(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK, app(ResolvesStockAvailability::class)->resolve($order)->status);

        $runId = (string) Str::uuid();
        $first = $this->snapshot([
            ['product_reference' => 'RRCHUNK-1', 'available_quantity' => 8, 'source_row_version' => 'new-1'],
        ], [
            'snapshot_run_id' => $runId, 'chunk_index' => 0, 'chunk_count' => 2, 'final_chunk' => false,
            'source_generated_at' => '2026-11-20T09:59:00Z',
        ]);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($first, JSON_THROW_ON_ERROR))->assertAccepted()
            ->assertJson(['run_status' => StockSnapshotRun::RECEIVING, 'chunk_index' => 0, 'chunk_count' => 2]);
        self::assertSame(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK, app(ResolvesStockAvailability::class)->resolve($order)->status);

        $second = $this->snapshot([
            ['product_reference' => 'RRCHUNK-2', 'available_quantity' => 1, 'source_row_version' => 'new-2'],
        ], [
            'snapshot_run_id' => $runId, 'chunk_index' => 1, 'chunk_count' => 2, 'final_chunk' => true,
            'source_generated_at' => '2026-11-20T09:59:00Z',
        ]);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($second, JSON_THROW_ON_ERROR))->assertAccepted()
            ->assertJson(['run_status' => StockSnapshotRun::COMPLETE]);
        self::assertSame(StockAvailabilityStatus::AVAILABLE_IN_AK, app(ResolvesStockAvailability::class)->resolve($order)->status);

        config(['integrations.pohoda_bridge.snapshot_run_timeout_minutes' => 1]);
        $incomplete = $this->snapshot([
            ['product_reference' => 'RRCHUNK-1', 'available_quantity' => 2, 'source_row_version' => 'timeout'],
        ], [
            'snapshot_run_id' => (string) Str::uuid(), 'chunk_index' => 0, 'chunk_count' => 2, 'final_chunk' => false,
            'source_generated_at' => '2026-11-20T10:00:00Z',
        ]);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($incomplete, JSON_THROW_ON_ERROR))->assertAccepted();
        Carbon::setTestNow('2026-11-20 10:02:00 UTC');
        $this->signed('POST', '/api/internal/v1/heartbeat', json_encode($this->heartbeat(), JSON_THROW_ON_ERROR))->assertAccepted();
        self::assertSame(StockSnapshotRun::FAILED, StockSnapshotRun::query()->where('run_id', $incomplete['snapshot_run_id'])->value('status'));

        $empty = $this->snapshot([], ['source_generated_at' => '2026-11-20T10:01:00Z']);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($empty, JSON_THROW_ON_ERROR))->assertAccepted()
            ->assertJson(['run_status' => StockSnapshotRun::COMPLETE, 'accepted_count' => 0]);
        self::assertSame(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK, app(ResolvesStockAvailability::class)->resolve($order)->status);
    }

    public function test_heartbeat_is_idempotent_and_rejects_sensitive_or_changed_payload(): void
    {
        $event = $this->heartbeat();
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $this->signed('POST', '/api/internal/v1/heartbeat', $body)->assertAccepted()->assertJson(['idempotent_replay' => false]);
        $this->signed('POST', '/api/internal/v1/heartbeat', $body)->assertAccepted()->assertJson(['idempotent_replay' => true]);
        $changed = $event;
        $changed['status'] = 'ERROR';
        $this->signed('POST', '/api/internal/v1/heartbeat', json_encode($changed, JSON_THROW_ON_ERROR))->assertConflict();
        $sensitive = $this->heartbeat(['event_id' => (string) Str::uuid(), 'connection_string' => 'SERVER=secret']);
        $this->signed('POST', '/api/internal/v1/heartbeat', json_encode($sensitive, JSON_THROW_ON_ERROR))->assertUnprocessable();
        self::assertSame(1, BridgeHeartbeat::query()->count());
    }

    public function test_command_claim_lease_expiry_result_idempotence_and_payload_whitelist(): void
    {
        $command = $this->command('NOOP', ['operation' => 'PING']);
        $unknown = $this->command('SET_PRICE', ['amount' => 1]);
        $unsafe = $this->command('NOOP', ['sql' => 'SELECT * FROM customers']);
        $first = $this->signed('GET', '/api/internal/v1/commands?connector_id=connector-a&limit=10')->assertOk();
        self::assertSame([$command->command_id], collect($first->json('commands'))->pluck('command_id')->all());
        $lease = $first->json('commands.0.lease_token');
        self::assertSame(BridgeCommand::PENDING, $unknown->fresh()->status);
        self::assertSame(BridgeCommand::FAILED, $unsafe->fresh()->status);
        $this->signed('GET', '/api/internal/v1/commands?connector_id=connector-b&limit=10')->assertOk()->assertJson(['commands' => []]);

        $command->update(['lease_until' => now()->subSecond()]);
        $second = $this->signed('GET', '/api/internal/v1/commands?connector_id=connector-b&limit=1')->assertOk();
        $newLease = $second->json('commands.0.lease_token');
        self::assertNotSame($lease, $newLease);
        self::assertSame(2, $command->fresh()->attempts);

        $result = ['result_id' => (string) Str::uuid(), 'lease_token' => $newLease, 'status' => 'SUCCESS',
            'completed_at' => now()->utc()->toIso8601String(), 'metadata' => ['duration_ms' => 12, 'message_code' => 'OK']];
        $uri = '/api/internal/v1/commands/'.$command->command_id.'/result';
        $body = json_encode($result, JSON_THROW_ON_ERROR);
        $this->signed('POST', $uri, $body)->assertAccepted()->assertJson(['idempotent_replay' => false]);
        $this->signed('POST', $uri, $body)->assertAccepted()->assertJson(['idempotent_replay' => true]);
        $changedResult = $result;
        $changedResult['status'] = 'ERROR';
        $changedResult['external_code'] = 'CHANGED';
        $this->signed('POST', $uri, json_encode($changedResult, JSON_THROW_ON_ERROR))->assertConflict();
        self::assertSame(1, BridgeCommandResult::query()->count());
        self::assertSame(BridgeCommand::SUCCEEDED, $command->fresh()->status);
    }

    public function test_admin_diagnostics_is_404_for_poland_read_only_and_contains_no_secrets_or_pii(): void
    {
        $secretMarker = base64_encode($this->currentSecret);
        $this->actingAs($this->poland)->get(route('admin.integrations.pohoda'))->assertNotFound();
        $this->actingAs($this->readOnly)->get(route('admin.integrations.pohoda'))->assertNotFound();
        $html = $this->actingAs($this->admin)->get(route('admin.integrations.pohoda'))->assertOk()->getContent();
        foreach ([$secretMarker, 'X-Bridge-Signature', 'customer@example.test', 'SELECT *', 'SERVER='] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        self::assertStringContainsString('STALE', $html);
    }

    public function test_admin_diagnostics_aggregates_mapping_and_preserves_old_rejected_run(): void
    {
        Carbon::setTestNow('2026-11-20 14:00:00 UTC');
        $oldRun = StockSnapshotRun::query()->create([
            'run_id' => (string) Str::uuid(),
            'warehouse_code' => 'DECIN',
            'source_generated_at' => now()->subMinutes(10),
            'chunk_count' => 5,
            'received_chunks' => 5,
            'accepted_count' => 0,
            'rejected_count' => 2484,
            'status' => StockSnapshotRun::PARTIAL,
            'expires_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(9),
        ]);
        StockSnapshotBatch::query()->create([
            'stock_snapshot_run_id' => $oldRun->id,
            'batch_id' => (string) Str::uuid(),
            'chunk_index' => 0,
            'chunk_count' => 5,
            'final_chunk' => false,
            'key_id' => 'current-test-key',
            'warehouse_code' => 'DECIN',
            'source_generated_at' => now()->subMinutes(10),
            'received_at' => now()->subMinutes(9),
            'status' => 'PARTIAL',
            'accepted_count' => 0,
            'rejected_count' => 2484,
            'payload_hash' => hash('sha256', 'preserved-production-history'),
        ]);

        $this->product('RRMAPPED-DIAG');
        $new = $this->snapshot([
            ['product_reference' => 'RRMAPPED-DIAG', 'available_quantity' => 1, 'source_row_version' => 'd1'],
            ['product_reference' => 'RRUNMAPPED-DIAG', 'available_quantity' => 2, 'source_row_version' => 'd2'],
            ['product_reference' => 'FOREIGN-DIAG', 'available_quantity' => 3, 'source_row_version' => 'd3'],
        ], ['source_generated_at' => '2026-11-20T13:59:00Z']);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($new, JSON_THROW_ON_ERROR))
            ->assertAccepted()->assertJson(['accepted_count' => 2, 'rejected_count' => 1]);

        $html = $this->actingAs($this->admin)->get(route('admin.integrations.pohoda'))->assertOk()->getContent();
        self::assertStringContainsString('Přijaté položky: 2', $html);
        self::assertStringContainsString('Namapované: 1', $html);
        self::assertStringContainsString('Nenamapované: 1', $html);
        self::assertStringContainsString('Odmítnuté položky: 1', $html);
        self::assertStringContainsString('PRODUCT_REFERENCE_NOT_ALLOWED: 1', $html);
        self::assertDatabaseHas('stock_snapshot_runs', [
            'id' => $oldRun->id,
            'status' => StockSnapshotRun::PARTIAL,
            'accepted_count' => 0,
            'rejected_count' => 2484,
        ]);
        self::assertSame(2, StockSnapshotRun::query()->count());
    }

    public function test_unchanged_confirmation_is_idempotent_refreshes_freshness_and_stores_no_items(): void
    {
        Carbon::setTestNow('2026-11-20 15:00:00 UTC');
        $fingerprint = hash('sha256', 'stable-dataset');
        $runId = (string) Str::uuid();
        $snapshot = $this->snapshot([
            ['product_reference' => 'RRCONFIRMED', 'available_quantity' => 4, 'reserved_quantity' => 1, 'source_row_version' => 'c1'],
        ], ['snapshot_run_id' => $runId, 'chunk_index' => 0, 'chunk_count' => 1, 'final_chunk' => true, 'dataset_fingerprint' => $fingerprint]);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($snapshot, JSON_THROW_ON_ERROR))
            ->assertAccepted()->assertJsonPath('dataset_fingerprint', $fingerprint);
        $order = Order::factory()->create(['product_code' => 'RRCONFIRMED']);
        $itemCount = StockSnapshotItem::query()->count();

        Carbon::setTestNow('2026-11-20 15:29:00 UTC');
        $confirmation = $this->confirmation($runId, $fingerprint);
        $body = json_encode($confirmation, JSON_THROW_ON_ERROR);
        $this->signed('POST', '/api/internal/v1/stock-snapshots/confirm-unchanged', $body)
            ->assertAccepted()->assertJson(['status' => 'UNCHANGED_CONFIRMED', 'base_status' => StockSnapshotRun::COMPLETE, 'idempotent_replay' => false]);
        $this->signed('POST', '/api/internal/v1/stock-snapshots/confirm-unchanged', $body)
            ->assertAccepted()->assertJson(['idempotent_replay' => true]);
        self::assertSame(1, StockSnapshotConfirmation::query()->count());
        self::assertSame($itemCount, StockSnapshotItem::query()->count());
        self::assertSame(StockAvailabilityStatus::AVAILABLE_IN_AK, app(ResolvesStockAvailability::class)->resolve($order)->status);
        config(['integrations.pohoda_bridge.allowed_command_types' => []]);
        $html = $this->actingAs($this->admin)->get(route('admin.integrations.pohoda'))->assertOk()->getContent();
        self::assertStringContainsString('Poslední potvrzení beze změny', $html);
        self::assertStringContainsString(substr($fingerprint, 0, 12).'…', $html);
        self::assertStringContainsString('Plné přenosy za 24 hodin: 1', $html);
        self::assertStringContainsString('Potvrzení za 24 hodin: 1', $html);
        self::assertStringContainsString('Poslední použitelný snapshot', $html);
        self::assertStringContainsString('Produkční command whitelist: prázdný', $html);

        Carbon::setTestNow('2026-11-20 16:00:01 UTC');
        self::assertSame(StockAvailabilityStatus::STALE, app(ResolvesStockAvailability::class)->resolve($order)->status);
        $this->signed('POST', '/api/internal/v1/stock-snapshots/confirm-unchanged', $body)
            ->assertAccepted()->assertJson(['idempotent_replay' => true]);
        self::assertSame(1, StockSnapshotConfirmation::query()->count());
        self::assertSame(StockAvailabilityStatus::STALE, app(ResolvesStockAvailability::class)->resolve($order)->status);
    }

    public function test_confirmation_rejects_mismatch_forged_base_warehouse_and_stale_timestamp(): void
    {
        Carbon::setTestNow('2026-11-20 16:00:00 UTC');
        $fingerprint = hash('sha256', 'base');
        $runId = (string) Str::uuid();
        $snapshot = $this->snapshot([], [
            'snapshot_run_id' => $runId, 'chunk_index' => 0, 'chunk_count' => 1, 'final_chunk' => true,
            'dataset_fingerprint' => $fingerprint,
        ]);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($snapshot, JSON_THROW_ON_ERROR))->assertAccepted();

        foreach ([
            $this->confirmation($runId, hash('sha256', 'different')),
            $this->confirmation((string) Str::uuid(), $fingerprint),
        ] as $payload) {
            $this->signed('POST', '/api/internal/v1/stock-snapshots/confirm-unchanged', json_encode($payload, JSON_THROW_ON_ERROR))
                ->assertConflict()->assertExactJson(['error' => ['code' => 'DATASET_FINGERPRINT_MISMATCH']]);
        }
        $wrongWarehouse = $this->confirmation($runId, $fingerprint, ['warehouse_code' => 'OTHER']);
        $this->signed('POST', '/api/internal/v1/stock-snapshots/confirm-unchanged', json_encode($wrongWarehouse, JSON_THROW_ON_ERROR))
            ->assertUnprocessable();
        $stale = $this->confirmation($runId, $fingerprint, ['source_generated_at' => now()->subMinutes(6)->format('Y-m-d\TH:i:s\Z')]);
        $this->signed('POST', '/api/internal/v1/stock-snapshots/confirm-unchanged', json_encode($stale, JSON_THROW_ON_ERROR))
            ->assertUnprocessable();
        self::assertSame(0, StockSnapshotConfirmation::query()->count());
    }

    public function test_confirmation_keeps_partial_base_and_missing_duplicate_reference_unknown(): void
    {
        Carbon::setTestNow('2026-11-20 17:00:00 UTC');
        $fingerprint = hash('sha256', 'partial');
        $runId = (string) Str::uuid();
        $snapshot = $this->snapshot([
            ['product_reference' => 'RRDUP', 'available_quantity' => 2, 'source_row_version' => '1'],
            ['product_reference' => 'RRDUP', 'available_quantity' => 3, 'source_row_version' => '2'],
        ], ['snapshot_run_id' => $runId, 'chunk_index' => 0, 'chunk_count' => 1, 'final_chunk' => true, 'dataset_fingerprint' => $fingerprint]);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($snapshot, JSON_THROW_ON_ERROR))
            ->assertAccepted()->assertJsonPath('run_status', StockSnapshotRun::PARTIAL);
        $payload = $this->confirmation($runId, $fingerprint);
        $this->signed('POST', '/api/internal/v1/stock-snapshots/confirm-unchanged', json_encode($payload, JSON_THROW_ON_ERROR))
            ->assertAccepted()->assertJsonPath('base_status', StockSnapshotRun::PARTIAL);
        self::assertSame(StockSnapshotRun::PARTIAL, StockSnapshotRun::query()->where('run_id', $runId)->value('status'));
        $duplicate = Order::factory()->create(['product_code' => 'RRDUP']);
        self::assertSame(StockAvailabilityStatus::UNKNOWN, app(ResolvesStockAvailability::class)->resolve($duplicate)->status);
    }

    public function test_bridge_calls_do_not_change_existing_workflows(): void
    {
        $order = Order::factory()->create();
        $thread = ProcessThread::factory()->create(['order_id' => $order->id, 'type' => ProcessThreadType::CUSTOM_REBUILD]);
        $before = [$thread->state, $thread->lock_version, $order->processThreads()->count()];
        $body = json_encode($this->heartbeat(), JSON_THROW_ON_ERROR);
        $this->signed('POST', '/api/internal/v1/heartbeat', $body)->assertAccepted();
        self::assertSame($before, [$thread->fresh()->state, $thread->fresh()->lock_version, $order->processThreads()->count()]);
    }

    public function test_production_bridge_exposes_no_pricing_or_sql_write_command(): void
    {
        config(['integrations.pohoda_bridge.allowed_command_types' => []]);
        $command = $this->command('UPDATE_PURCHASE_PRICE', [
            'product_reference' => 'RRPRICE', 'purchase_price_minor' => 10000,
        ]);
        $this->signed('GET', '/api/internal/v1/commands?connector_id=connector-a&limit=50', '', queryForSignature: [
            'connector_id' => 'connector-a', 'limit' => '50',
        ])->assertOk()->assertExactJson(['commands' => []]);
        self::assertSame(BridgeCommand::PENDING, $command->fresh()->status);
        self::assertArrayNotHasKey('pohoda', config('database.connections'));
        self::assertStringNotContainsString('repric', strtolower(file_get_contents(base_path('routes/api.php'))));
    }

    public function test_capacity_for_2484_rows_five_chunks_and_288_daily_confirmations_is_bounded(): void
    {
        Carbon::setTestNow('2026-11-20 23:55:00 UTC');
        $items = [];
        for ($index = 1; $index <= 2482; $index++) {
            $items[] = [
                'product_reference' => 'RRCAP'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'available_quantity' => $index % 3,
                'reserved_quantity' => $index % 2,
                'source_row_version' => 'capacity-'.$index,
            ];
        }
        $items[] = ['product_reference' => 'RRCAP0001', 'available_quantity' => 1, 'reserved_quantity' => 0, 'source_row_version' => 'duplicate-1'];
        $items[] = ['product_reference' => 'RRCAP0002', 'available_quantity' => 1, 'reserved_quantity' => 0, 'source_row_version' => 'duplicate-2'];
        $chunks = array_chunk($items, 500);
        self::assertCount(5, $chunks);
        $runId = (string) Str::uuid();
        $fingerprint = hash('sha256', 'capacity-2484');
        $sourceAt = now()->utc()->format('Y-m-d\TH:i:s\Z');
        foreach ($chunks as $index => $chunk) {
            $payload = $this->snapshot($chunk, [
                'snapshot_run_id' => $runId,
                'chunk_index' => $index,
                'chunk_count' => 5,
                'final_chunk' => $index === 4,
                'dataset_fingerprint' => $fingerprint,
                'source_generated_at' => $sourceAt,
            ]);
            $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($payload, JSON_THROW_ON_ERROR))->assertAccepted();
        }
        $run = StockSnapshotRun::query()->where('run_id', $runId)->firstOrFail();
        self::assertSame(StockSnapshotRun::PARTIAL, $run->status);
        self::assertSame(2482, $run->accepted_count);
        self::assertSame(2, $run->rejected_count);
        self::assertSame(2484, StockSnapshotItem::query()->count());

        $confirmation = $this->confirmation($runId, $fingerprint);
        $this->signed('POST', '/api/internal/v1/stock-snapshots/confirm-unchanged', json_encode($confirmation, JSON_THROW_ON_ERROR))->assertAccepted();
        self::assertSame(2484, StockSnapshotItem::query()->count());
        self::assertSame(1, StockSnapshotRun::query()->count());

        $rows = [];
        for ($index = 1; $index < 288; $index++) {
            $receivedAt = now()->subMinutes($index * 5);
            $rows[] = [
                'confirmation_id' => (string) Str::uuid(),
                'stock_snapshot_run_id' => $run->id,
                'key_id' => 'current-test-key',
                'warehouse_code' => 'DECIN',
                'dataset_fingerprint' => $fingerprint,
                'source_generated_at' => $receivedAt,
                'received_at' => $receivedAt,
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            StockSnapshotConfirmation::query()->insert($chunk);
        }
        self::assertSame(288, StockSnapshotConfirmation::query()->count());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->actingAs($this->admin)->get(route('admin.integrations.pohoda'))->assertOk()->getContent();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        self::assertLessThanOrEqual(15, $queries);
        self::assertStringContainsString('Potvrzení za 24 hodin: 288', $html);
        self::assertStringContainsString('Nenamapované: 2482', $html);
        self::assertStringContainsString('Odmítnuté položky: 2', $html);
        self::assertStringContainsString('DUPLICATE_PRODUCT_REFERENCE: 2', $html);
        self::assertStringContainsString('receiving: 0 · failed: 0', $html);
    }

    private function signed(
        string $method,
        string $uri,
        string $body = '',
        ?string $nonce = null,
        ?int $timestamp = null,
        string $keyId = 'current-test-key',
        ?string $secret = null,
        ?string $signature = null,
        ?string $signedBody = null,
        ?array $queryForSignature = null,
        string $ip = '127.0.0.1',
        string $userAgent = 'Phase7aTestConnector/1.0',
        bool $secure = true,
    ) {
        $nonce ??= 'nonce_'.Str::random(32);
        $timestamp ??= now()->timestamp;
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);
        $query = $queryForSignature ?? $query;
        $canonical = implode("\n", [
            strtoupper($method), $parts['path'], BridgeCanonicalRequest::canonicalQuery($query),
            (string) $timestamp, $nonce, hash('sha256', $signedBody ?? $body),
        ]);
        $signature ??= hash_hmac('sha256', $canonical, $secret ?? $this->currentSecret);

        $requestUri = ($secure ? 'https://localhost' : 'http://localhost').$uri;

        return $this->call($method, $requestUri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BRIDGE_KEY_ID' => $keyId, 'HTTP_X_BRIDGE_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_BRIDGE_NONCE' => $nonce, 'HTTP_X_BRIDGE_SIGNATURE' => $signature,
            'REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $userAgent,
            'HTTPS' => $secure ? 'on' : 'off', 'SERVER_PORT' => $secure ? 443 : 80,
            'REQUEST_SCHEME' => $secure ? 'https' : 'http',
        ], $body);
    }

    private function heartbeat(array $override = []): array
    {
        return array_replace([
            'event_id' => (string) Str::uuid(), 'connector_id' => 'connector-a', 'connector_version' => '7A-test',
            'occurred_at' => now()->utc()->toIso8601String(), 'last_pohoda_read_at' => null,
            'last_snapshot_at' => null, 'last_command_poll_at' => now()->utc()->toIso8601String(), 'status' => 'OK',
        ], $override);
    }

    private function snapshot(array $items, array $override = []): array
    {
        return array_replace([
            'batch_id' => (string) Str::uuid(), 'source_generated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'warehouse_code' => 'DECIN', 'items' => $items,
        ], $override);
    }

    private function confirmation(string $runId, string $fingerprint, array $override = []): array
    {
        return array_replace([
            'confirmation_id' => (string) Str::uuid(),
            'base_snapshot_run_id' => $runId,
            'dataset_fingerprint' => $fingerprint,
            'warehouse_code' => 'DECIN',
            'source_generated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ], $override);
    }

    private function product(string $reference): LegacyProductConfiguration
    {
        return LegacyProductConfiguration::query()->create(['product_reference' => $reference]);
    }

    private function command(string $type, array $payload): BridgeCommand
    {
        return BridgeCommand::query()->create([
            'command_id' => (string) Str::uuid(), 'type' => $type, 'payload' => $payload,
            'status' => BridgeCommand::PENDING, 'available_at' => now(),
        ]);
    }
}
