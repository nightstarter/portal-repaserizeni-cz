<?php

namespace Tests\Feature;

use App\Backup\BackupManifestVerifier;
use App\Backup\RestoreCheckService;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\User;
use App\Operations\PreflightCheck;
use App\Operations\ProductionPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\BackupFixture;
use Tests\TestCase;

class Phase8bBackupRecoveryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<BackupFixture> */
    private array $fixtures = [];

    /** @var list<string> */
    private array $targets = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'integrations.legacy_shop.enabled' => false,
            'discussion_notifications.enabled' => false,
            'buyback.customer_email_enabled' => false,
            'integrations.pohoda_bridge.enabled' => false,
            'integrations.pohoda_bridge.allowed_command_types' => [],
            'operations.product_repricing' => 'DEFERRED_EXTERNAL_SYSTEM',
            'backups.testing_fail_after_copied_parts' => null,
        ]);
    }

    protected function tearDown(): void
    {
        config(['backups.testing_fail_after_copied_parts' => null]);
        foreach ($this->targets as $target) {
            if (is_dir($target) && str_starts_with($target, realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'portal-phase8b-restore-')) {
                File::deleteDirectory($target);
            }
        }
        foreach ($this->fixtures as $fixture) {
            $fixture->cleanup();
        }
        parent::tearDown();
    }

    public function test_plan_and_verify_support_sanitized_json_and_exit_codes(): void
    {
        $fixture = $this->fixture();
        config([
            'database.connections.sqlite.password' => 'db-never-print',
            'mail.mailers.smtp.password' => 'smtp-never-print',
            'integrations.pohoda_bridge.secret' => 'bridge-never-print',
        ]);

        $this->assertSame(0, Artisan::call('backup:plan', ['--json' => true]));
        $plan = Artisan::output();
        $this->assertSame('PLAN_ONLY', json_decode($plan, true, flags: JSON_THROW_ON_ERROR)['status']);
        $this->assertStringNotContainsString('never-print', $plan);

        $this->assertSame(0, Artisan::call('backup:verify', ['path' => $fixture->root(), '--json' => true]));
        $output = Artisan::output();
        $this->assertTrue(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['valid']);
        $this->assertStringNotContainsString('never-print', $output);

        file_put_contents($fixture->root().'/database.sql', 'tampered');
        $this->assertSame(1, Artisan::call('backup:verify', ['path' => $fixture->root(), '--json' => true]));
        $this->assertFalse(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['valid']);
    }

    public function test_invalid_incomplete_secret_and_checksum_manifests_are_rejected(): void
    {
        $invalid = $this->fixture();
        file_put_contents($invalid->root().'/manifest.json', '{invalid');
        $this->assertFalse(app(BackupManifestVerifier::class)->verify($invalid->root())->valid);

        $incomplete = $this->fixture();
        $manifest = $incomplete->manifest();
        $manifest['status'] = 'FAILED';
        $incomplete->rewrite($manifest);
        $this->assertFalse(app(BackupManifestVerifier::class)->verify($incomplete->root())->valid);

        $secret = $this->fixture();
        $manifest = $secret->manifest();
        $manifest['app_key'] = 'base64:must-not-appear';
        $secret->rewrite($manifest);
        $result = app(BackupManifestVerifier::class)->verify($secret->root());
        $this->assertFalse($result->valid);
        $this->assertStringNotContainsString('must-not-appear', json_encode($result->toArray()));
    }

    public function test_manifest_rejects_path_traversal_and_symlink_escape(): void
    {
        $traversal = $this->fixture();
        $manifest = $traversal->manifest();
        $manifest['parts'][0]['path'] = '../database.sql';
        $traversal->rewrite($manifest);
        $this->assertFalse(app(BackupManifestVerifier::class)->verify($traversal->root())->valid);

        $symlink = $this->fixture();
        symlink('/etc/hosts', $symlink->root().'/escape');
        $manifest = $symlink->manifest();
        $manifest['parts'][] = [
            'name' => 'escape', 'kind' => 'private-file', 'path' => 'escape', 'source_path' => 'escape',
            'size' => filesize('/etc/hosts'), 'sha256' => hash_file('sha256', '/etc/hosts'), 'mode' => '0600',
        ];
        $symlink->rewrite($manifest);
        $this->assertFalse(app(BackupManifestVerifier::class)->verify($symlink->root())->valid);
    }

    public function test_restore_rejects_production_environment_database_and_storage(): void
    {
        $fixture = $this->fixture();
        $target = $this->target();
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->assertSame(1, Artisan::call('backup:restore-check', ['path' => $fixture->root(), '--target' => $target, '--json' => true]));
        $this->assertDirectoryDoesNotExist($target);
    }

    public function test_restore_rejects_production_database_identity(): void
    {
        $fixture = $this->fixture();
        config(['backups.production_database' => ['host' => null, 'port' => null, 'database' => ':memory:']]);

        $this->assertSame(1, Artisan::call('backup:restore-check', [
            'path' => $fixture->root(), '--target' => $this->target(), '--json' => true,
        ]));

        config(['backups.production_database' => ['host' => null, 'port' => null, 'database' => null]]);
        $manifest = $fixture->manifest();
        $manifest['database']['source_identity_sha256'] = hash('sha256', 'sqlite|||:memory:');
        $fixture->rewrite($manifest);
        $this->assertSame(1, Artisan::call('backup:restore-check', [
            'path' => $fixture->root(), '--target' => $this->target(), '--json' => true,
        ]));
    }

    public function test_restore_rejects_production_private_storage_and_nonempty_target_without_deleting_foreign_files(): void
    {
        $fixture = $this->fixture();
        $productionStorage = (string) config('filesystems.disks.local.root');
        $this->assertSame(1, Artisan::call('backup:restore-check', [
            'path' => $fixture->root(), '--target' => $productionStorage, '--json' => true,
        ]));

        $target = $this->target(true);
        file_put_contents($target.'/foreign.keep', 'preserve');
        $this->assertSame(1, Artisan::call('backup:restore-check', [
            'path' => $fixture->root(), '--target' => $target, '--json' => true,
        ]));
        $this->assertFileExists($target.'/foreign.keep');
        $this->assertSame('preserve', file_get_contents($target.'/foreign.keep'));
    }

    public function test_restore_interruption_removes_only_tool_created_sandbox(): void
    {
        [$documentPath, $contents] = $this->documentFixture();
        $fixture = $this->fixture([$documentPath => $contents]);
        $target = $this->target();
        config(['backups.testing_fail_after_copied_parts' => 1]);

        $this->assertSame(1, Artisan::call('backup:restore-check', [
            'path' => $fixture->root(), '--target' => $target, '--json' => true,
        ]));
        $this->assertDirectoryDoesNotExist($target);

        $existingEmpty = $this->target(true);
        $this->assertSame(1, Artisan::call('backup:restore-check', [
            'path' => $fixture->root(), '--target' => $existingEmpty, '--json' => true,
        ]));
        $this->assertDirectoryExists($existingEmpty);
        $this->assertSame(['.', '..'], scandir($existingEmpty));
    }

    public function test_isolated_fixture_restore_checks_counts_constraints_files_and_read_only_page(): void
    {
        [$documentPath, $contents, $user] = $this->documentFixture(true);
        $fixture = $this->fixture([$documentPath => $contents]);
        $target = $this->target();

        $serviceResult = app(RestoreCheckService::class)->run($fixture->root(), $target);
        $this->assertSame('OK', $serviceResult['status']);
        $target = $this->target();

        $this->assertSame(0, Artisan::call('backup:restore-check', [
            'path' => $fixture->root(), '--target' => $target, '--json' => true,
        ]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('OK', $result['status']);
        $this->assertSame(1, $result['copied_private_files']);
        $this->assertFileExists($target.'/'.$documentPath);
        $this->assertSame($contents, file_get_contents($target.'/'.$documentPath));
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_preflight_classifies_missing_old_and_valid_production_backup(): void
    {
        config(['backups.latest_manifest' => null]);
        $missing = collect(app(ProductionPreflight::class)->run())->keyBy('id');
        $this->assertSame(PreflightCheck::ERROR, $missing['latest_backup']->status);

        $old = $this->fixture([], ['created_at_utc' => now()->subDays(2)->utc()->format('Y-m-d\TH:i:s\Z')]);
        config(['backups.latest_manifest' => $old->root(), 'backups.maximum_age_hours' => 26]);
        $oldChecks = collect(app(ProductionPreflight::class)->run())->keyBy('id');
        $this->assertSame(PreflightCheck::ERROR, $oldChecks['latest_backup']->status);

        $valid = $this->fixture([], [
            'off_server' => ['confirmed' => true, 'confirmed_at_utc' => now()->utc()->format('Y-m-d\TH:i:s\Z')],
            'restore_test' => ['status' => 'SUCCESS', 'completed_at_utc' => now()->utc()->format('Y-m-d\TH:i:s\Z')],
        ]);
        config(['backups.latest_manifest' => $valid->root()]);
        $checks = collect(app(ProductionPreflight::class)->run())->keyBy('id');
        $this->assertSame(PreflightCheck::OK, $checks['latest_backup']->status);
        $this->assertSame(PreflightCheck::OK, $checks['recovery_evidence']->status);
    }

    private function fixture(array $files = [], array $overrides = []): BackupFixture
    {
        return $this->fixtures[] = BackupFixture::create($files, $overrides);
    }

    private function target(bool $create = false): string
    {
        $target = sys_get_temp_dir().DIRECTORY_SEPARATOR.'portal-phase8b-restore-'.Str::uuid();
        $this->targets[] = $target;
        if ($create) {
            mkdir($target, 0700);
        }

        return $target;
    }

    private function documentFixture(bool $returnUser = false): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $order = Order::factory()->create();
        $thread = ProcessThread::factory()->create(['order_id' => $order->id]);
        $path = 'documents/'.Str::uuid().'.pdf';
        $contents = '%PDF-1.4 isolated restore fixture';
        Document::query()->create([
            'public_token' => (string) Str::uuid(), 'order_id' => $order->id, 'process_thread_id' => $thread->id,
            'type' => DocumentType::SUPPLIER_INVOICE, 'storage_disk' => 'local', 'storage_name' => $path,
            'original_name' => 'fixture.pdf', 'mime' => 'application/pdf', 'extension' => 'pdf',
            'size' => strlen($contents), 'checksum_sha256' => hash('sha256', $contents), 'document_number' => 'FIXTURE',
            'document_date' => now()->toDateString(), 'amount' => '1.00', 'currency' => 'CZK',
            'uploaded_by' => $user->id, 'uploaded_organization_id' => $organization->id,
            'status' => DocumentStatus::ACTIVE,
        ]);

        return $returnUser ? [$path, $contents, $user] : [$path, $contents];
    }
}
