<?php

namespace Tests\Feature;

use App\Backup\Contracts\BackupMaintenance;
use App\Backup\Contracts\DatabaseDumpRunner;
use App\Backup\Contracts\PrivateStorageArchiver;
use App\Backup\DumpException;
use App\Backup\MariaDbDumpRunner;
use App\Backup\PrivateStoragePolicy;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class Phase8bBackupCreateTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'backups.testing_git_commit' => str_repeat('c', 40),
            'backups.testing_tamper_after_manifest' => false,
            'backups.testing_source_identity_sha256' => hash('sha256', 'different-isolated-source'),
            'integrations.legacy_shop.enabled' => false,
            'discussion_notifications.enabled' => false,
            'buyback.customer_email_enabled' => false,
            'integrations.pohoda_bridge.enabled' => false,
            'integrations.pohoda_bridge.allowed_command_types' => [],
            'operations.product_repricing' => 'DEFERRED_EXTERNAL_SYSTEM',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $path) {
            if (is_dir($path) && ! is_link($path)) {
                File::deleteDirectory($path);
            } elseif (is_link($path) || is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_create_publishes_complete_verified_package_with_json_exit_zero(): void
    {
        $target = $this->preparePrivateDocumentAndTarget();
        $this->bindSuccessfulDump($target);

        $exit = Artisan::call('backup:create', ['--target' => $target, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('COMPLETE', $result['status']);
        $this->assertDirectoryExists($target);
        $this->assertFileExists($target.'/database.sql');
        $this->assertFileExists($target.'/private-storage.tar');
        $this->assertFileExists($target.'/metadata.json');
        $this->assertFileExists($target.'/manifest.json');
        $manifest = json_decode((string) file_get_contents($target.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['database-dump', 'private-archive', 'metadata'], array_column($manifest['parts'], 'kind'));
        $privatePart = collect($manifest['parts'])->firstWhere('kind', 'private-archive');
        $excludedPaths = array_column(app(PrivateStoragePolicy::class)->excludedOperationalArtifacts(), 'path');
        $this->assertSame([], array_intersect($excludedPaths, array_column($privatePart['entries'], 'path')));
        $this->assertSame(app(PrivateStoragePolicy::class)->excludedOperationalArtifacts(), $manifest['private_storage']['excluded_operational_artifacts']);
        $metadata = json_decode((string) file_get_contents($target.'/metadata.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($manifest['private_storage'], $metadata['private_storage']);
        $archivePaths = [];
        foreach (new \RecursiveIteratorIterator(new \PharData($target.'/private-storage.tar')) as $key => $file) {
            if ($file->isFile()) {
                $archivePaths[] = basename((string) $key);
            }
        }
        $this->assertSame([], array_intersect($excludedPaths, $archivePaths));
        $privateRoot = (string) config('filesystems.disks.local.root');
        $this->assertFileExists($privateRoot.'/.gitignore');
        $this->assertFileExists($privateRoot.'/legacy-shop-phase1c-report.json');
        $this->assertSame(0, Artisan::call('backup:verify', ['path' => $target, '--json' => true]));
        // Recovery evidence is intentionally restricted to production COMPLETE manifests.
        // This isolated SQLite fixture simulates that manifest state without touching production.
        $manifest['environment'] = 'production';
        file_put_contents($target.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->assertSame(0, Artisan::call('backup:verify', ['path' => $target, '--json' => true]));
        $restoreTarget = $this->newTarget();
        $this->assertSame(0, Artisan::call('backup:restore-check', [
            'path' => $target, '--target' => $restoreTarget, '--evidence-id' => 'restore-create-e2e', '--json' => true,
        ]));
        $this->assertFileDoesNotExist($restoreTarget.'/.gitignore');
        $this->assertFileDoesNotExist($restoreTarget.'/legacy-shop-phase1c-report.json');
        $this->assertStringNotContainsString('APP_KEY', file_get_contents($target.'/metadata.json'));
        $this->assertStringNotContainsString('password', strtolower($output));
    }

    public function test_dump_failure_cleans_staging_preserves_foreign_file_and_leaves_maintenance(): void
    {
        $target = $this->preparePrivateDocumentAndTarget();
        $foreign = dirname($target).'/foreign-'.Str::uuid().'.keep';
        file_put_contents($foreign, 'preserve');
        $this->cleanup[] = $foreign;
        $this->app->bind(DatabaseDumpRunner::class, fn () => new class implements DatabaseDumpRunner
        {
            public function dump(string $outputPath, string $credentialsDirectory): array
            {
                throw new RuntimeException('dump password=must-not-print');
            }
        });
        $maintenance = $this->recordingMaintenance();

        $this->assertSame(1, Artisan::call('backup:create', ['--target' => $target, '--json' => true]));
        $this->assertDirectoryDoesNotExist($target);
        $this->assertFileExists($foreign);
        $this->assertSame('preserve', file_get_contents($foreign));
        $this->assertSame(1, $maintenance->begins);
        $this->assertSame(1, $maintenance->ends);
        $this->assertStringNotContainsString('must-not-print', Artisan::output());
        $this->assertSame([], glob(dirname($target).'/.'.basename($target).'.staging-*'));
    }

    public function test_classified_dump_failure_is_safe_in_json_and_keeps_exit_one(): void
    {
        $target = $this->preparePrivateDocumentAndTarget();
        $this->app->bind(DatabaseDumpRunner::class, fn () => new class implements DatabaseDumpRunner
        {
            public function dump(string $outputPath, string $credentialsDirectory): array
            {
                throw new DumpException(
                    'DUMP_AUTHENTICATION_FAILED',
                    'database_dump',
                    2,
                    'mariadb-dump',
                    'Verify separately managed credentials.',
                );
            }
        });

        $this->assertSame(1, Artisan::call('backup:create', ['--target' => $target, '--json' => true]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('DUMP_AUTHENTICATION_FAILED', $result['code']);
        $this->assertSame('DATABASE_DUMP', $result['phase']);
        $this->assertSame(2, $result['process_exit_code']);
        $this->assertSame('mariadb-dump', $result['binary']);
        $this->assertDirectoryDoesNotExist($target);
    }

    public function test_artisan_propagates_every_phase_in_json_and_text_without_internal_details(): void
    {
        $firstTarget = $this->preparePrivateDocumentAndTarget();
        $phases = [
            'DATABASE_DUMP' => 'DUMP_FAILED_UNKNOWN',
            'PRIVATE_ARCHIVE' => 'PRIVATE_ARCHIVE_FAILED',
            'METADATA' => 'METADATA_FAILED',
            'MANIFEST' => 'MANIFEST_FAILED',
            'VERIFY' => 'VERIFY_FAILED',
            'FINALIZE' => 'FINALIZE_FAILED',
            'MAINTENANCE' => 'MAINTENANCE_FAILED',
            'UNKNOWN' => 'BACKUP_FAILED_UNKNOWN',
        ];

        foreach ($phases as $phase => $code) {
            foreach ([true, false] as $json) {
                $target = $phase === 'DATABASE_DUMP' && $json ? $firstTarget : $this->newTarget();
                $this->bindSuccessfulDump($target);
                config(['backups.testing_fail_phase' => $phase]);

                $arguments = ['--target' => $target];
                if ($json) {
                    $arguments['--json'] = true;
                }
                $this->assertSame(1, Artisan::call('backup:create', $arguments), $phase);
                $output = Artisan::output();
                $this->assertStringNotContainsString('phase-failure-canary', $output);
                $this->assertDirectoryDoesNotExist($target);

                if ($json) {
                    $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
                    $this->assertSame('ERROR', $result['status']);
                    $this->assertSame($code, $result['code']);
                    $this->assertSame($phase, $result['phase']);
                    $this->assertIsString($result['recommendation']);
                    $this->assertArrayNotHasKey('message', $result);
                } else {
                    $this->assertStringContainsString($code, $output);
                    $this->assertStringContainsString('Phase: '.$phase, $output);
                    $this->assertStringContainsString('Recommendation:', $output);
                }
            }
        }
        config(['backups.testing_fail_phase' => null]);
    }

    public function test_maintenance_finally_failure_does_not_replace_classified_dump_failure(): void
    {
        $target = $this->preparePrivateDocumentAndTarget();
        $this->app->bind(DatabaseDumpRunner::class, fn () => new class implements DatabaseDumpRunner
        {
            public function dump(string $outputPath, string $credentialsDirectory): array
            {
                throw new DumpException('DUMP_CONNECTION_FAILED', 'dump_process', 9, 'mariadb-dump', 'Safe dump recommendation.');
            }
        });
        $this->app->instance(BackupMaintenance::class, new class implements BackupMaintenance
        {
            public function begin(): bool
            {
                return true;
            }

            public function end(bool $enabledByThisInvocation): void
            {
                throw new RuntimeException('maintenance-secret-canary');
            }
        });

        $this->assertSame(1, Artisan::call('backup:create', ['--target' => $target, '--json' => true]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('DUMP_CONNECTION_FAILED', $result['code']);
        $this->assertSame('DATABASE_DUMP', $result['phase']);
        $this->assertSame(9, $result['process_exit_code']);
        $this->assertStringContainsString('maintenance mode', $result['recommendation']);
        $this->assertStringNotContainsString('maintenance-secret-canary', Artisan::output());
    }

    public function test_archive_failure_and_checksum_mismatch_never_publish_complete_manifest(): void
    {
        $target = $this->preparePrivateDocumentAndTarget();
        $this->bindSuccessfulDump($target);
        $real = app(PrivateStorageArchiver::class);
        $this->app->instance(PrivateStorageArchiver::class, new class($real) implements PrivateStorageArchiver
        {
            public function __construct(private readonly PrivateStorageArchiver $real) {}

            public function inventory(string $source): array
            {
                return $this->real->inventory($source);
            }

            public function archive(string $source, string $outputPath): array
            {
                throw new RuntimeException('archive-canary-must-not-print');
            }
        });
        $this->assertSame(1, Artisan::call('backup:create', ['--target' => $target, '--json' => true]));
        $this->assertDirectoryDoesNotExist($target);
        $this->assertStringNotContainsString('archive-canary-must-not-print', Artisan::output());

        $target = $this->newTarget();
        $foreign = dirname($target).'/foreign-verify-'.Str::uuid().'.keep';
        file_put_contents($foreign, 'preserve');
        $this->cleanup[] = $foreign;
        $this->bindSuccessfulDump($target);
        $this->app->forgetInstance(PrivateStorageArchiver::class);
        config(['backups.testing_tamper_after_manifest' => true]);
        $this->assertSame(1, Artisan::call('backup:create', ['--target' => $target, '--json' => true]));
        $verificationFailure = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('VERIFY_FAILED', $verificationFailure['code']);
        $this->assertSame('VERIFY', $verificationFailure['phase']);
        $this->assertSame('COMPONENT_SIZE_MISMATCH', $verificationFailure['verification_code']);
        $this->assertSame('database.sql', $verificationFailure['component']);
        $this->assertDirectoryDoesNotExist($target);
        $this->assertSame('preserve', file_get_contents($foreign));
        $this->assertSame([], glob(dirname($target).'/.'.basename($target).'.staging-*'));
    }

    public function test_target_created_during_staging_is_never_overwritten_or_cleaned(): void
    {
        $target = $this->preparePrivateDocumentAndTarget();
        $this->app->bind(DatabaseDumpRunner::class, fn () => new class($target) implements DatabaseDumpRunner
        {
            public function __construct(private readonly string $target) {}

            public function dump(string $outputPath, string $credentialsDirectory): array
            {
                mkdir($this->target, 0700);
                file_put_contents($this->target.'/foreign.keep', 'preserve');
                file_put_contents($outputPath, '-- fake isolated SQLite dump');

                return Phase8bBackupCreateTest::dumpMetadata();
            }
        });

        $this->assertSame(1, Artisan::call('backup:create', ['--target' => $target, '--json' => true]));
        $this->assertFileExists($target.'/foreign.keep');
        $this->assertSame('preserve', file_get_contents($target.'/foreign.keep'));
        $this->assertFileDoesNotExist($target.'/manifest.json');
        $this->assertSame([], glob(dirname($target).'/.'.basename($target).'.staging-*'));
    }

    public function test_non_control_orphan_is_classified_and_never_published(): void
    {
        $target = $this->preparePrivateDocumentAndTarget();
        $privateRoot = (string) config('filesystems.disks.local.root');
        file_put_contents($privateRoot.'/orphan.bin', 'not referenced by the database');
        $this->bindSuccessfulDump($target);

        $this->assertSame(1, Artisan::call('backup:create', ['--target' => $target, '--json' => true]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('VERIFY_FAILED', $result['code']);
        $this->assertSame('PRIVATE_INVENTORY_MISMATCH', $result['verification_code']);
        $this->assertSame('private-storage.tar', $result['component']);
        $this->assertDirectoryDoesNotExist($target);
        $this->assertSame([], glob(dirname($target).'/.'.basename($target).'.staging-*'));
    }

    public function test_similar_and_nested_operational_artifact_names_remain_blocked(): void
    {
        $firstTarget = $this->preparePrivateDocumentAndTarget();
        $privateRoot = (string) config('filesystems.disks.local.root');
        $paths = [
            'legacy-shop-phase1c-report-copy.json',
            'nested/legacy-shop-phase1c-report.json',
            'customer-report.json',
            '.gitignore.bak',
            'nested/orphan.bin',
        ];

        foreach ($paths as $index => $path) {
            $target = $index === 0 ? $firstTarget : $this->newTarget();
            if (! is_dir(dirname($privateRoot.'/'.$path))) {
                mkdir(dirname($privateRoot.'/'.$path), 0700, true);
            }
            file_put_contents($privateRoot.'/'.$path, 'must remain subject to orphan policy');
            $this->bindSuccessfulDump($target);

            $this->assertSame(1, Artisan::call('backup:create', ['--target' => $target, '--json' => true]), $path);
            $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('PRIVATE_INVENTORY_MISMATCH', $result['verification_code'], $path);
            $this->assertDirectoryDoesNotExist($target);
            unlink($privateRoot.'/'.$path);
        }
    }

    public function test_create_rejects_existing_nonempty_protected_tmp_and_symlink_targets(): void
    {
        $this->assertSame(1, Artisan::call('backup:create', ['--json' => true]));
        $missingTarget = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([
            'status' => 'ERROR',
            'code' => 'FINALIZE_FAILED',
            'phase' => 'FINALIZE',
        ], collect($missingTarget)->only(['status', 'code', 'phase'])->all());
        $this->assertIsString($missingTarget['recommendation']);
        $this->assertCreateRejected(base_path('forbidden-backup'));
        $this->assertCreateRejected(public_path('forbidden-backup'));
        $this->assertCreateRejected(storage_path('forbidden-backup'));
        $this->assertCreateRejected((string) config('filesystems.disks.local.root').'/forbidden-backup');
        $this->assertCreateRejected(DIRECTORY_SEPARATOR);
        $this->assertCreateRejected(sys_get_temp_dir());
        $this->assertCreateRejected(sys_get_temp_dir().'/not-unique');

        $existing = $this->newTarget();
        mkdir($existing, 0700);
        $this->assertCreateRejected($existing);
        $this->assertSame(['.', '..'], scandir($existing));
        File::deleteDirectory($existing);

        $existing = $this->newTarget();
        mkdir($existing, 0700);
        file_put_contents($existing.'/foreign.keep', 'preserve');
        $this->assertCreateRejected($existing);
        $this->assertFileExists($existing.'/foreign.keep');

        $realParent = sys_get_temp_dir().'/portal-backup-parent-'.Str::uuid();
        mkdir($realParent, 0700);
        $this->cleanup[] = $realParent;
        $link = sys_get_temp_dir().'/portal-backup-link-'.Str::uuid();
        symlink($realParent, $link);
        $this->cleanup[] = $link;
        $this->assertCreateRejected($link.'/portal-backup-'.Str::uuid());
    }

    public function test_credentials_file_is_mode_0600_and_removed_when_fake_dump_binary_fails(): void
    {
        $directory = sys_get_temp_dir().'/portal-backup-credentials-'.Str::uuid();
        mkdir($directory, 0700);
        $this->cleanup[] = $directory;
        $binary = $directory.'/fake-mariadb-dump';
        $modeMarker = $directory.'/credentials-mode';
        $argumentsMarker = $directory.'/arguments';
        file_put_contents($binary, "#!/usr/bin/php8.5\n<?php\n".
            '$path = substr($argv[1], strlen("--defaults-extra-file="));'."\n".
            'file_put_contents('.var_export($modeMarker, true).', substr(sprintf("%o", fileperms($path)), -3));'."\n".
            'file_put_contents('.var_export($argumentsMarker, true).', implode(" ", $argv));'."\n".
            "exit(7);\n");
        chmod($binary, 0700);
        config([
            'backups.dump_binary' => $binary,
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'fixture',
                'username' => 'fixture', 'password' => 'password-must-not-print', 'unix_socket' => null,
            ],
        ]);

        try {
            app(MariaDbDumpRunner::class)->dump($directory.'/database.sql', $directory);
            $this->fail('Dump failure was expected.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('password-must-not-print', $exception->getMessage());
        }
        $this->assertSame('600', file_get_contents($modeMarker));
        $this->assertStringNotContainsString('password-must-not-print', file_get_contents($argumentsMarker));
        $this->assertSame([], glob($directory.'/.mariadb-client-*.cnf'));
        $this->assertFileDoesNotExist($directory.'/database.sql');
    }

    private function preparePrivateDocumentAndTarget(): string
    {
        $private = sys_get_temp_dir().'/portal-phase8b-private-'.Str::uuid();
        mkdir($private, 0700);
        $this->cleanup[] = $private;
        config(['filesystems.disks.local.root' => $private]);
        file_put_contents($private.'/.gitignore', "*\n!.gitignore\n");
        file_put_contents($private.'/legacy-shop-phase1c-report.json', '{"status":"anonymized-fixture"}');

        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $order = Order::factory()->create();
        $thread = ProcessThread::factory()->create(['order_id' => $order->id]);
        $path = 'documents/'.Str::uuid().'.pdf';
        mkdir($private.'/documents', 0700);
        $contents = '%PDF-1.4 create fixture';
        file_put_contents($private.'/'.$path, $contents);
        chmod($private.'/'.$path, 0600);
        Document::query()->create([
            'public_token' => (string) Str::uuid(), 'order_id' => $order->id, 'process_thread_id' => $thread->id,
            'type' => DocumentType::SUPPLIER_INVOICE, 'storage_disk' => 'local', 'storage_name' => $path,
            'original_name' => 'fixture.pdf', 'mime' => 'application/pdf', 'extension' => 'pdf',
            'size' => strlen($contents), 'checksum_sha256' => hash('sha256', $contents), 'document_number' => 'FIXTURE',
            'document_date' => now()->toDateString(), 'amount' => '1.00', 'currency' => 'CZK',
            'uploaded_by' => $user->id, 'uploaded_organization_id' => $organization->id, 'status' => DocumentStatus::ACTIVE,
        ]);

        return $this->newTarget();
    }

    private function newTarget(): string
    {
        $target = sys_get_temp_dir().'/portal-backup-'.Str::uuid();
        $this->cleanup[] = $target;

        return $target;
    }

    private function bindSuccessfulDump(string $target): void
    {
        $this->app->bind(DatabaseDumpRunner::class, fn () => new class($target) implements DatabaseDumpRunner
        {
            public function __construct(private readonly string $target) {}

            public function dump(string $outputPath, string $credentialsDirectory): array
            {
                if (file_exists($this->target)) {
                    throw new RuntimeException('Final target was published before dump completed.');
                }
                file_put_contents($outputPath, '-- fake isolated SQLite dump');
                chmod($outputPath, 0600);

                return Phase8bBackupCreateTest::dumpMetadata();
            }
        });
    }

    public static function dumpMetadata(): array
    {
        return [
            'logical_objects' => ['triggers' => 0, 'routines' => 0, 'events' => 0],
            'options' => [
                'single_transaction' => true, 'quick' => true, 'skip_lock_tables' => true,
                'no_tablespaces' => true, 'triggers' => false, 'routines' => false, 'events' => false,
            ],
        ];
    }

    private function recordingMaintenance(): object
    {
        $maintenance = new class implements BackupMaintenance
        {
            public int $begins = 0;

            public int $ends = 0;

            public function begin(): bool
            {
                $this->begins++;

                return true;
            }

            public function end(bool $enabledByThisInvocation): void
            {
                if ($enabledByThisInvocation) {
                    $this->ends++;
                }
            }
        };
        $this->app->instance(BackupMaintenance::class, $maintenance);

        return $maintenance;
    }

    private function assertCreateRejected(string $target): void
    {
        $this->assertSame(1, Artisan::call('backup:create', ['--target' => $target, '--json' => true]));
        $this->assertSame('ERROR', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status']);
    }
}
