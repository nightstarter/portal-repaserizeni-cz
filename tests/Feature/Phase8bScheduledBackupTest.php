<?php

namespace Tests\Feature;

use App\Backup\BackupCoordinator;
use App\Backup\BackupCreator;
use App\Backup\LatestBackupManifest;
use App\Backup\ScheduledBackupTarget;
use App\Models\ScheduledBackupRun;
use App\Operations\BackupEvidenceHealth;
use App\Operations\MonitorCheck;
use App\Operations\MonitoringSuite;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Support\BackupFixture;
use Tests\TestCase;

class Phase8bScheduledBackupTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<BackupFixture> */
    private array $fixtures = [];

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'backups.scheduled_enabled' => false,
            'backups.root' => sys_get_temp_dir(),
            'backups.latest_pointer' => sys_get_temp_dir().'/portal-latest-'.Str::uuid().'.json',
            'backups.latest_manifest' => null,
            'backups.schedule_lock_minutes' => 120,
            'backups.testing_pointer_fail_before_rename' => false,
        ]);
        $this->files[] = (string) config('backups.latest_pointer');
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->cleanup();
        }
        foreach ($this->files as $file) {
            if (is_file($file) || is_link($file)) {
                @unlink($file);
            } elseif (is_dir($file)) {
                @rmdir($file);
            }
        }
        parent::tearDown();
    }

    public function test_disabled_command_records_safe_skip_without_creating_backup(): void
    {
        $coordinator = Mockery::mock(BackupCoordinator::class);
        $coordinator->shouldNotReceive('create');
        $latest = Mockery::mock(LatestBackupManifest::class);
        $latest->shouldNotReceive('publish');
        $this->app->instance(BackupCoordinator::class, $coordinator);
        $this->app->instance(LatestBackupManifest::class, $latest);

        $this->assertSame(0, Artisan::call('backup:scheduled', ['--json' => true]));
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(ScheduledBackupRun::SKIPPED_DISABLED, $payload['status']);
        $this->assertDatabaseHas('scheduled_backup_runs', ['status' => ScheduledBackupRun::SKIPPED_DISABLED]);
    }

    public function test_utc_target_is_canonical_unique_and_rejects_unsafe_root(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-25 23:59:58', 'UTC'));
        $target = app(ScheduledBackupTarget::class)->make();
        $this->assertSame(sys_get_temp_dir().'/portal-backup-20261125T235958Z', $target);

        mkdir($target, 0700);
        $this->files[] = $target;
        $this->expectException(RuntimeException::class);
        app(ScheduledBackupTarget::class)->make();
    }

    public function test_manual_and_scheduled_use_same_lock_and_locked_run_is_recorded(): void
    {
        $coordinator = new BackupCoordinator(app(BackupCreator::class));
        $lock = Cache::lock(BackupCoordinator::LOCK_NAME, 7200);
        $this->assertTrue($lock->get());
        try {
            $this->app->instance(BackupCoordinator::class, $coordinator);
            $this->assertSame(1, Artisan::call('backup:create', ['--target' => sys_get_temp_dir().'/portal-backup-'.Str::uuid(), '--json' => true]));
            $this->assertSame('BACKUP_LOCKED', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['code']);

            config(['backups.scheduled_enabled' => true]);
            $this->assertSame(0, Artisan::call('backup:scheduled', ['--json' => true]));
            $this->assertSame(ScheduledBackupRun::SKIPPED_LOCKED, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status']);
            $this->assertDatabaseHas('scheduled_backup_runs', ['status' => ScheduledBackupRun::SKIPPED_LOCKED, 'failure_code' => 'BACKUP_LOCKED']);
        } finally {
            $lock->release();
        }
    }

    public function test_pointer_is_atomic_verified_and_preserved_on_failure(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        $latest = app(LatestBackupManifest::class);
        $latest->publish($first->root());
        $before = file_get_contents((string) config('backups.latest_pointer'));
        $this->assertSame(realpath($first->root().'/manifest.json'), $latest->resolve());
        $this->assertSame('600', substr(sprintf('%o', fileperms((string) config('backups.latest_pointer'))), -3));

        config(['backups.testing_pointer_fail_before_rename' => true]);
        try {
            $latest->publish($second->root());
            $this->fail('Pointer publication failure was expected.');
        } catch (RuntimeException) {
            $this->assertSame($before, file_get_contents((string) config('backups.latest_pointer')));
            $this->assertSame(realpath($first->root().'/manifest.json'), $latest->resolve());
        }
        $this->assertSame([], glob(dirname((string) config('backups.latest_pointer')).'/.'.basename((string) config('backups.latest_pointer')).'.tmp-*'));
        $this->assertFileDoesNotExist($first->root().'/recovery-evidence.json');
    }

    public function test_latest_health_uses_pointer_then_compatible_manifest_fallback(): void
    {
        $fixture = $this->fixture();
        config(['backups.latest_manifest' => $fixture->root().'/manifest.json']);
        $health = app(BackupEvidenceHealth::class)->latest();
        $this->assertSame('WARN', $health['status']);
        $this->assertTrue($health['manifest_valid']);

        app(LatestBackupManifest::class)->publish($fixture->root());
        config(['backups.latest_manifest' => '/secret/fallback-must-not-be-read']);
        $this->assertSame('WARN', app(BackupEvidenceHealth::class)->latest()['status']);
    }

    public function test_success_failure_and_pointer_failure_are_persisted_and_sanitized(): void
    {
        config(['backups.scheduled_enabled' => true]);
        $latest = Mockery::mock(LatestBackupManifest::class);
        $latest->shouldReceive('publish')->once();
        $this->app->instance(LatestBackupManifest::class, $latest);
        $coordinator = Mockery::mock(BackupCoordinator::class);
        $coordinator->shouldReceive('create')->once()->andReturn([
            'status' => 'COMPLETE', 'backup_id' => 'backup-safe-id', 'created_at_utc' => now()->utc()->toIso8601String(),
        ]);
        $this->app->instance(BackupCoordinator::class, $coordinator);
        $this->assertSame(0, Artisan::call('backup:scheduled', ['--json' => true]));
        $this->assertSame(ScheduledBackupRun::SUCCESS, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status']);

        $coordinator = Mockery::mock(BackupCoordinator::class);
        $coordinator->shouldReceive('create')->once()->andThrow(new RuntimeException('password=secret host=private'));
        $this->app->instance(BackupCoordinator::class, $coordinator);
        $this->assertSame(1, Artisan::call('backup:scheduled', ['--json' => true]));
        $output = Artisan::output();
        $this->assertSame('BACKUP_FAILED_UNKNOWN', json_decode($output, true, flags: JSON_THROW_ON_ERROR)['failure_code']);
        $this->assertStringNotContainsString('secret', $output);
        $this->assertStringNotContainsString('private', $output);

        $coordinator = Mockery::mock(BackupCoordinator::class);
        $coordinator->shouldReceive('create')->once()->andReturn(['backup_id' => 'backup-pointer-failure']);
        $latest = Mockery::mock(LatestBackupManifest::class);
        $latest->shouldReceive('publish')->once()->andThrow(new RuntimeException('pointer-secret'));
        $this->app->instance(BackupCoordinator::class, $coordinator);
        $this->app->instance(LatestBackupManifest::class, $latest);
        $this->assertSame(1, Artisan::call('backup:scheduled', ['--json' => true]));
        $this->assertSame('POINTER_UPDATE_FAILED', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['failure_code']);
        $this->assertDatabaseCount('scheduled_backup_runs', 3);
    }

    public function test_scheduler_is_guarded_and_monitor_maps_all_persisted_states(): void
    {
        $event = collect(Schedule::events())->first(fn (Event $event): bool => str_contains($event->command ?? '', 'backup:scheduled'));
        $this->assertNotNull($event);
        $this->assertSame('30 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(120, $event->expiresAt);
        $this->assertFalse($event->filtersPass(app()));

        config(['backups.scheduled_enabled' => true]);
        $this->assertTrue($event->filtersPass(app()));
        $expected = [
            ScheduledBackupRun::SUCCESS => MonitorCheck::OK,
            ScheduledBackupRun::FAILED => MonitorCheck::ERROR,
            ScheduledBackupRun::SKIPPED_DISABLED => MonitorCheck::WARN,
            ScheduledBackupRun::SKIPPED_LOCKED => MonitorCheck::WARN,
        ];
        foreach ($expected as $status => $severity) {
            ScheduledBackupRun::query()->delete();
            ScheduledBackupRun::query()->create([
                'run_uuid' => (string) Str::uuid(), 'status' => $status,
                'failure_code' => $status === ScheduledBackupRun::FAILED ? 'SAFE_FAILURE' : null,
                'started_at' => now()->subMinute(), 'completed_at' => now(),
            ]);
            $check = collect(app(MonitoringSuite::class)->run('scheduled_backup'))->first();
            $this->assertSame($severity, $check->severity, $status);
            $this->assertSame($status, $check->metadata['last_status']);
        }
    }

    private function fixture(): BackupFixture
    {
        $fixture = BackupFixture::create();
        $this->fixtures[] = $fixture;

        return $fixture;
    }
}
