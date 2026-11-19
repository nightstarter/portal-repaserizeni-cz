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
use App\Models\StockSnapshotItem;
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

    public function test_snapshot_is_partial_per_item_idempotent_and_unknown_products_are_recorded(): void
    {
        $this->product('KNOWN-1');
        $payload = $this->snapshot([
            ['product_reference' => 'KNOWN-1', 'available_quantity' => 3, 'reserved_quantity' => 1, 'source_row_version' => 'v1'],
            ['product_reference' => 'KNOWN-2', 'available_quantity' => 5, 'source_row_version' => 'v2'],
            ['product_reference' => 'KNOWN-1', 'available_quantity' => -1, 'source_row_version' => 'v3'],
        ]);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $response = $this->signed('POST', '/api/internal/v1/stock-snapshots', $body)->assertAccepted()
            ->assertJson(['status' => 'PARTIAL', 'accepted_count' => 1, 'rejected_count' => 2, 'idempotent_replay' => false]);
        self::assertSame(['ACCEPTED', 'REJECTED', 'REJECTED'], collect($response->json('items'))->pluck('status')->all());
        self::assertSame(['UNKNOWN_PRODUCT_REFERENCE', 'INVALID_ITEM'], collect($response->json('items'))->pluck('code')->filter()->values()->all());
        $this->signed('POST', '/api/internal/v1/stock-snapshots', $body)->assertAccepted()->assertJson(['idempotent_replay' => true]);
        self::assertSame(1, StockSnapshotBatch::query()->count());
        self::assertSame(3, StockSnapshotItem::query()->count());

        $changed = $payload;
        $changed['warehouse_code'] = 'OTHER';
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($changed, JSON_THROW_ON_ERROR))->assertConflict();
        self::assertSame(1, StockSnapshotBatch::query()->count());
    }

    public function test_snapshot_item_and_raw_body_limits_are_enforced(): void
    {
        config(['integrations.pohoda_bridge.max_batch_items' => 1]);
        $payload = $this->snapshot([
            ['product_reference' => 'ONE', 'available_quantity' => 1, 'source_row_version' => '1'],
            ['product_reference' => 'TWO', 'available_quantity' => 2, 'source_row_version' => '2'],
        ]);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($payload, JSON_THROW_ON_ERROR))->assertUnprocessable();
        self::assertSame(0, StockSnapshotBatch::query()->count());

        config(['integrations.pohoda_bridge.max_body_bytes' => 1024]);
        $oversized = str_repeat('x', 1025);
        $this->signed('POST', '/api/internal/v1/heartbeat', $oversized)->assertStatus(413)
            ->assertExactJson(['error' => ['code' => 'REQUEST_TOO_LARGE']]);
    }

    public function test_newer_snapshot_wins_and_stale_snapshot_is_not_conclusive(): void
    {
        Carbon::setTestNow('2026-11-19 12:00:00 UTC');
        $this->product('STOCK-1');
        $order = Order::factory()->create(['product_code' => 'STOCK-1']);
        $newer = $this->snapshot([
            ['product_reference' => 'STOCK-1', 'available_quantity' => 0, 'source_row_version' => 'new'],
        ], ['source_generated_at' => '2026-11-19T11:55:00Z']);
        $older = $this->snapshot([
            ['product_reference' => 'STOCK-1', 'available_quantity' => 9, 'source_row_version' => 'old'],
        ], ['source_generated_at' => '2026-11-19T11:50:00Z']);
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($newer, JSON_THROW_ON_ERROR))->assertAccepted();
        $this->signed('POST', '/api/internal/v1/stock-snapshots', json_encode($older, JSON_THROW_ON_ERROR))->assertAccepted();
        self::assertSame(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK, app(ResolvesStockAvailability::class)->resolve($order)->status);

        Carbon::setTestNow('2026-11-19 12:31:00 UTC');
        self::assertSame(StockAvailabilityStatus::STALE, app(ResolvesStockAvailability::class)->resolve($order)->status);
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

    public function test_bridge_calls_do_not_change_existing_workflows(): void
    {
        $order = Order::factory()->create();
        $thread = ProcessThread::factory()->create(['order_id' => $order->id, 'type' => ProcessThreadType::CUSTOM_REBUILD]);
        $before = [$thread->state, $thread->lock_version, $order->processThreads()->count()];
        $body = json_encode($this->heartbeat(), JSON_THROW_ON_ERROR);
        $this->signed('POST', '/api/internal/v1/heartbeat', $body)->assertAccepted();
        self::assertSame($before, [$thread->fresh()->state, $thread->fresh()->lock_version, $order->processThreads()->count()]);
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

        return $this->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BRIDGE_KEY_ID' => $keyId, 'HTTP_X_BRIDGE_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_BRIDGE_NONCE' => $nonce, 'HTTP_X_BRIDGE_SIGNATURE' => $signature,
            'REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $userAgent,
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
