<?php

namespace Tests\Feature;

use App\Enums\OrganizationCode;
use App\Enums\UserRole;
use App\Models\MonitorRun;
use App\Models\OperationalAlert;
use App\Models\Organization;
use App\Models\SchedulerHeartbeat;
use App\Models\User;
use App\Operations\Contracts\TlsCertificateInspector;
use App\Operations\MonitorCheck;
use App\Operations\MonitoringSuite;
use App\Operations\OperationalAlertManager;
use App\Operations\OperationalAlertRouter;
use App\Operations\ProductionPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class Phase8cMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private string $privateRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateRoot = sys_get_temp_dir().'/phase8c-private-'.Str::uuid();
        mkdir($this->privateRoot, 0700, true);
        config([
            'filesystems.disks.local.root' => $this->privateRoot,
            'filesystems.disks.local.visibility' => 'private',
            'cache.default' => 'array',
            'operations.monitoring.error_after_occurrences' => 3,
            'operations.monitoring.critical_after_minutes' => 30,
            'operations.monitoring.email_enabled' => false,
            'operations.monitoring.enabled' => false,
            'integrations.legacy_shop.enabled' => false,
            'discussion_notifications.enabled' => false,
            'buyback.customer_email_enabled' => false,
            'integrations.pohoda_bridge.allowed_command_types' => [],
            'operations.product_repricing' => 'DEFERRED_EXTERNAL_SYSTEM',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->privateRoot)) {
            @rmdir($this->privateRoot);
        }
        parent::tearDown();
    }

    public function test_alert_deduplication_fingerprint_resolution_and_reopen_cycles(): void
    {
        $manager = app(OperationalAlertManager::class);
        $firstRun = $this->runRecord();
        $check = new MonitorCheck('disk_space', MonitorCheck::WARN, 'Disk vyžaduje pozornost.', ['free_megabytes' => 900]);

        $manager->apply([$check], $firstRun);
        $manager->apply([$check], $this->runRecord());

        $alert = OperationalAlert::query()->firstOrFail();
        $this->assertSame(2, $alert->occurrence_count);
        $this->assertSame(OperationalAlert::OPEN, $alert->lifecycle);
        $this->assertSame(2, $alert->events()->count());

        $changed = new MonitorCheck('disk_space', MonitorCheck::ERROR, 'Disk je pod kritickým provozním limitem.', ['free_megabytes' => 100]);
        $manager->apply([$changed], $this->runRecord());
        $this->assertSame(2, OperationalAlert::query()->count());
        $this->assertSame(1, OperationalAlert::query()->where('lifecycle', OperationalAlert::RESOLVED)->count());
        $active = OperationalAlert::query()->where('lifecycle', OperationalAlert::OPEN)->firstOrFail();

        $manager->apply([new MonitorCheck('disk_space', MonitorCheck::OK, 'Disk je v pořádku.')], $this->runRecord());
        $this->assertSame(OperationalAlert::RESOLVED, $active->fresh()->lifecycle);

        $manager->apply([$changed], $this->runRecord());
        $this->assertSame(3, OperationalAlert::query()->count());
        $this->assertSame(1, OperationalAlert::query()->where('lifecycle', OperationalAlert::OPEN)->count());
    }

    public function test_warn_escalates_and_append_only_history_rejects_mutation(): void
    {
        $manager = app(OperationalAlertManager::class);
        $check = new MonitorCheck('integration_delivery', MonitorCheck::WARN, 'Doručování má retry.', ['retry_count' => 1]);
        foreach (range(1, 3) as $_) {
            $manager->apply([$check], $this->runRecord());
        }
        $alert = OperationalAlert::query()->firstOrFail();
        $this->assertSame(MonitorCheck::ERROR, $alert->severity);
        $this->assertSame('ESCALATED', $alert->events()->reorder('id', 'desc')->value('event_type'));

        $event = $alert->events()->firstOrFail();
        $this->expectException(LogicException::class);
        $event->update(['summary' => 'mutace']);
    }

    public function test_ack_requires_note_authorization_and_current_row_version(): void
    {
        [$admin, $operator, $pl, $readOnly] = $this->users();
        $alert = $this->openAlert();
        foreach ([$operator, $pl, $readOnly] as $user) {
            $this->actingAs($user)->get(route('admin.operations.monitoring.index'))->assertNotFound();
            $this->actingAs($user)->get(route('admin.operations.monitoring.show', $alert))->assertNotFound();
            $this->actingAs($user)->post(route('admin.operations.monitoring.acknowledge', $alert), ['note' => 'OPS-1', 'row_version' => 1])->assertNotFound();
        }

        $this->actingAs($admin)->post(route('admin.operations.monitoring.acknowledge', $alert), ['note' => '', 'row_version' => 1])->assertSessionHasErrors('note');
        $this->actingAs($admin)->post(route('admin.operations.monitoring.acknowledge', $alert), ['note' => 'INC-8C převzato', 'row_version' => 1])->assertRedirect();
        $this->assertSame(OperationalAlert::ACKNOWLEDGED, $alert->fresh()->lifecycle);
        $this->assertDatabaseHas('audit_log', ['action' => 'OPERATIONAL_ALERT_ACKNOWLEDGED', 'subject_id' => $alert->id]);
        $this->actingAs($admin)->post(route('admin.operations.monitoring.acknowledge', $alert), ['note' => 'stará verze', 'row_version' => 1])->assertStatus(409);
    }

    public function test_cli_json_no_notify_check_filter_exit_codes_and_global_lock(): void
    {
        config(['operations.monitoring.email_enabled' => true, 'operations.monitoring.email_recipients' => ['ops@example.test']]);
        Mail::fake();

        $exit = Artisan::call('ops:monitor', ['--json' => true, '--no-notify' => true, '--check' => 'connector_runtime_debt']);
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame('connector_runtime_debt', $payload['checks'][0]['key']);
        Mail::assertNothingSent();

        $exit = Artisan::call('ops:monitor', ['--json' => true, '--check' => 'application_environment']);
        $this->assertSame(1, $exit);
        $this->assertSame(MonitorCheck::CRITICAL, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status']);

        $lock = Cache::lock('ops-monitor-global', 240);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame(1, Artisan::call('ops:monitor', ['--json' => true, '--check' => 'disk_space']));
            $this->assertTrue(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['lock_contended']);
        } finally {
            $lock->release();
        }
    }

    public function test_scheduler_heartbeat_distinguishes_disabled_and_enabled_monitoring(): void
    {
        config(['operations.monitoring.enabled' => false]);
        $this->assertSame(0, Artisan::call('ops:scheduler-heartbeat'));
        $heartbeat = SchedulerHeartbeat::query()->findOrFail('laravel');
        $this->assertFalse($heartbeat->monitor_enabled);

        config(['operations.monitoring.enabled' => true]);
        Artisan::call('ops:scheduler-heartbeat');
        $this->assertTrue($heartbeat->fresh()->monitor_enabled);

        MonitorRun::query()->create([
            'run_uuid' => (string) Str::uuid(), 'status' => 'RUNNING', 'started_at' => now()->subHour(),
        ]);
        $check = collect(app(MonitoringSuite::class)->run('monitor_overlap'))->first();
        $this->assertSame(MonitorCheck::ERROR, $check->severity);
        $this->assertSame(1, $check->metadata['stale_run_count']);
    }

    public function test_expired_global_lock_is_recovered_and_private_orphan_output_is_aggregated(): void
    {
        $expired = Cache::lock('ops-monitor-global', 1);
        $this->assertTrue($expired->get());
        $this->travel(2)->seconds();
        $this->assertSame(0, Artisan::call('ops:monitor', ['--json' => true, '--no-notify' => true, '--check' => 'connector_runtime_debt']));

        Storage::disk('local')->put('customer-secret-document.pdf', 'test');
        $check = collect(app(MonitoringSuite::class)->run('private_storage_inventory'))->first();
        $encoded = json_encode($check->toArray(), JSON_THROW_ON_ERROR);
        $this->assertSame(MonitorCheck::ERROR, $check->severity);
        $this->assertSame(1, $check->metadata['orphan_count']);
        $this->assertStringNotContainsString('customer-secret-document', $encoded);
        Storage::disk('local')->delete('customer-secret-document.pdf');
    }

    public function test_preflight_failure_does_not_prevent_independent_checks(): void
    {
        $this->app->instance(ProductionPreflight::class, new class extends ProductionPreflight
        {
            public function __construct() {}

            public function run(): array
            {
                throw new LogicException('sensitive internal detail');
            }
        });
        $checks = collect($this->app->make(MonitoringSuite::class)->run())->keyBy('key');

        $this->assertSame(MonitorCheck::ERROR, $checks['preflight_internal']->severity);
        $this->assertTrue($checks->has('monitor_overlap'));
        $this->assertTrue($checks->has('tls_certificate'));
        $this->assertStringNotContainsString('sensitive internal detail', json_encode($checks->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_tls_check_handles_ok_expiry_and_verified_connection_failure_via_fake(): void
    {
        config(['operations.monitoring.tls_enabled' => true, 'operations.monitoring.tls_host' => 'portal.example.test']);
        foreach ([
            [['ok' => true, 'code' => 'OK', 'days_remaining' => 60], MonitorCheck::OK],
            [['ok' => true, 'code' => 'OK', 'days_remaining' => 20], MonitorCheck::WARN],
            [['ok' => true, 'code' => 'OK', 'days_remaining' => 3], MonitorCheck::ERROR],
            [['ok' => false, 'code' => 'TLS_VERIFY_FAILED', 'days_remaining' => null], MonitorCheck::ERROR],
            [['ok' => false, 'code' => 'TLS_CONNECTION_FAILED', 'days_remaining' => null], MonitorCheck::ERROR],
        ] as [$result, $severity]) {
            $this->app->instance(TlsCertificateInspector::class, new class($result) implements TlsCertificateInspector
            {
                public function __construct(private readonly array $result) {}

                public function inspect(string $host, int $port, int $timeoutSeconds): array
                {
                    return $this->result;
                }
            });
            $check = collect(app(MonitoringSuite::class)->run('tls_certificate'))->first();
            $this->assertSame($severity, $check->severity);
        }
    }

    public function test_email_is_fake_cooldown_applies_and_recovery_is_sent_once(): void
    {
        Mail::fake();
        config([
            'operations.monitoring.email_enabled' => true,
            'operations.monitoring.email_recipients' => ['ops@example.test'],
            'operations.monitoring.cooldown_minutes' => 30,
        ]);
        $router = app(OperationalAlertRouter::class);
        $alert = $this->openAlert(['severity' => MonitorCheck::ERROR]);
        $router->route([['alert' => $alert, 'event' => 'OPENED']], false);
        $router->route([['alert' => $alert->fresh(), 'event' => 'OCCURRED']], false);
        Mail::assertSentCount(1);

        $alert->forceFill(['lifecycle' => OperationalAlert::RESOLVED])->save();
        $router->route([['alert' => $alert, 'event' => 'RESOLVED']], false);
        $router->route([['alert' => $alert->fresh(), 'event' => 'RESOLVED']], false);
        Mail::assertSentCount(2);
    }

    public function test_public_health_is_minimal_and_admin_query_count_is_constant(): void
    {
        $response = $this->get('/health')->assertOk();
        $this->assertSame('OK', $response->getContent());
        $this->assertStringNotContainsString('Laravel', $response->getContent());
        $this->assertStringNotContainsString('database', strtolower($response->getContent()));

        [$admin] = $this->users();
        $this->actingAs($admin);
        foreach (range(1, 2) as $index) {
            $this->openAlert(['check_key' => 'small_'.$index, 'fingerprint' => hash('sha256', 'small'.$index)]);
        }
        $small = $this->selectCount(route('admin.operations.monitoring.index'));
        foreach (range(3, 100) as $index) {
            $this->openAlert(['check_key' => 'large_'.$index, 'fingerprint' => hash('sha256', 'large'.$index)]);
        }
        $large = $this->selectCount(route('admin.operations.monitoring.index'));
        $this->assertLessThanOrEqual($small + 1, $large);
        $this->assertLessThanOrEqual(8, $large);
    }

    private function runRecord(): MonitorRun
    {
        return MonitorRun::query()->create([
            'run_uuid' => (string) Str::uuid(), 'status' => MonitorCheck::OK,
            'started_at' => now(), 'completed_at' => now(),
        ]);
    }

    private function openAlert(array $overrides = []): OperationalAlert
    {
        $fingerprint = $overrides['fingerprint'] ?? hash('sha256', (string) Str::uuid());
        $key = $overrides['check_key'] ?? 'disk_space';

        return OperationalAlert::query()->create(array_merge([
            'check_key' => $key, 'severity' => MonitorCheck::WARN, 'lifecycle' => OperationalAlert::OPEN,
            'fingerprint' => $fingerprint, 'active_dedup_key' => $key.'|'.$fingerprint,
            'summary' => 'Bezpečné provozní shrnutí.', 'safe_metadata' => ['count' => 1],
            'occurrence_count' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(), 'row_version' => 1,
        ], $overrides));
    }

    /** @return array{User,User,User,User} */
    private function users(): array
    {
        $ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY]);
        $plOrg = Organization::factory()->create(['code' => OrganizationCode::POLAND]);

        return [
            User::factory()->create(['organization_id' => $ak->id, 'role' => UserRole::AK_ADMIN]),
            User::factory()->create(['organization_id' => $ak->id, 'role' => UserRole::AK_OPERATOR]),
            User::factory()->create(['organization_id' => $plOrg->id, 'role' => UserRole::PL_OPERATOR]),
            User::factory()->create(['organization_id' => $ak->id, 'role' => UserRole::READ_ONLY]),
        ];
    }

    private function selectCount(string $url): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $count++;
            }
        });
        $this->get($url)->assertOk();

        return $count;
    }
}
