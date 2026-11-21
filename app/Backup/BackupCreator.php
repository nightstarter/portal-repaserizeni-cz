<?php

namespace App\Backup;

use App\Backup\Contracts\BackupMaintenance;
use App\Backup\Contracts\DatabaseDumpRunner;
use App\Backup\Contracts\PrivateStorageArchiver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class BackupCreator
{
    public function __construct(
        private readonly BackupPathGuard $paths,
        private readonly BackupManifestVerifier $verifier,
        private readonly DatabaseDumpRunner $dumpRunner,
        private readonly PrivateStorageArchiver $archiver,
        private readonly BackupMaintenance $maintenance,
        private readonly PrivateStoragePolicy $privateStoragePolicy,
    ) {}

    public function create(string $requestedTarget): array
    {
        $phase = 'UNKNOWN';
        $target = null;
        $staging = null;
        $maintenanceEnabled = false;
        $published = false;
        $failure = null;
        $result = null;
        try {
            $this->failForTesting('UNKNOWN');
            if (! app()->environment(['production', 'testing'])) {
                throw new RuntimeException('Backup creation is allowed only in production or testing.');
            }

            $phase = 'FINALIZE';
            $target = $this->paths->newBackupTarget($requestedTarget);
            $staging = dirname($target).DIRECTORY_SEPARATOR.'.'.basename($target).'.staging-'.Str::uuid();
            if (! mkdir($staging, 0700)) {
                throw new RuntimeException('Unable to create private backup staging directory.');
            }

            $phase = 'MAINTENANCE';
            $this->failForTesting($phase);
            $maintenanceEnabled = $this->maintenance->begin();

            $phase = 'METADATA';
            $before = $this->databaseState();
            $privateRoot = (string) config('filesystems.disks.local.root');

            $phase = 'PRIVATE_ARCHIVE';
            $privateBefore = $this->archiver->inventory($privateRoot);

            $phase = 'DATABASE_DUMP';
            $this->failForTesting($phase);
            $dumpPath = $staging.DIRECTORY_SEPARATOR.'database.sql';
            $dumpMetadata = $this->dumpRunner->dump($dumpPath, $staging);

            $phase = 'PRIVATE_ARCHIVE';
            $this->failForTesting($phase);
            $archivePath = $staging.DIRECTORY_SEPARATOR.'private-storage.tar';
            $archiveEntries = $this->archiver->archive($privateRoot, $archivePath);

            $phase = 'VERIFY';
            $this->failForTesting($phase);
            $after = $this->databaseState();
            $privateAfter = $this->archiver->inventory($privateRoot);
            if ($before !== $after || $privateBefore !== $archiveEntries || $archiveEntries !== $privateAfter) {
                throw $this->verificationFailure('VERIFY_INTERNAL_ERROR', null, 'Database or private storage changed during backup creation; keep all external writers stopped for the complete maintenance window.');
            }
            $this->verifyDocumentLinks($archiveEntries);

            $phase = 'METADATA';
            $this->failForTesting($phase);
            $createdAt = now()->utc()->format('Y-m-d\TH:i:s\Z');
            $gitCommit = $this->gitCommit();
            $databaseVersion = $this->databaseVersion();
            $metadata = [
                'backup_id' => 'backup-'.now()->utc()->format('Ymd\THis\Z').'-'.Str::uuid(),
                'created_at_utc' => $createdAt,
                'git_commit' => $gitCommit,
                'runtime' => [
                    'portal_php' => PHP_VERSION,
                    'mariadb' => $databaseVersion,
                    'connector_php' => (string) config('operations.preflight.connector_php_version', '7.4.33'),
                    'connector_os' => (string) config('operations.preflight.connector_os_version', 'Windows Server 2012 R2'),
                ],
                'schema' => $after['schema'],
                'critical_counts' => $after['critical_counts'],
                'database_dump' => $dumpMetadata,
                'private_storage' => [
                    'excluded_operational_artifacts' => $this->privateStoragePolicy->excludedOperationalArtifacts(),
                ],
                'private_file_count' => count($archiveEntries),
            ];
            $metadataPath = $staging.DIRECTORY_SEPARATOR.'metadata.json';
            $this->writeJson($metadataPath, $metadata);

            $phase = 'MANIFEST';
            $this->failForTesting($phase);
            $manifest = $this->manifest($metadata, $databaseVersion, $archiveEntries, $staging);
            $this->verifyBeforeComplete($manifest, $staging);
            $manifest['status'] = 'COMPLETE';
            $this->writeJsonAtomically($staging.DIRECTORY_SEPARATOR.'manifest.json', $manifest);

            if ($this->isTestingProcess() && config('backups.testing_tamper_after_manifest')) {
                file_put_contents($dumpPath, 'tampered', FILE_APPEND);
            }

            $phase = 'VERIFY';
            $stagingVerification = $this->verifier->verify($staging);
            if (! $stagingVerification->valid) {
                throw $this->verificationFailureFromResult($stagingVerification);
            }

            $phase = 'FINALIZE';
            $this->failForTesting($phase);
            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException('Backup target appeared before atomic publication.');
            }
            if (! rename($staging, $target)) {
                throw new RuntimeException('Unable to publish backup atomically.');
            }
            $published = true;

            $phase = 'VERIFY';
            $finalVerification = $this->verifier->verify($target);
            if (! $finalVerification->valid) {
                throw $this->verificationFailureFromResult($finalVerification);
            }

            $result = [
                'status' => 'COMPLETE',
                'backup_id' => $manifest['backup_id'],
                'target' => $target,
                'created_at_utc' => $createdAt,
                'parts' => count($manifest['parts']),
                'private_files' => count($archiveEntries),
            ];
        } catch (Throwable $exception) {
            $failure = $this->classifyFailure($exception, $phase);
            try {
                if ($published && is_string($target) && is_dir($target)) {
                    File::deleteDirectory($target);
                }
                if (is_string($staging) && is_dir($staging)) {
                    File::deleteDirectory($staging);
                }
            } catch (Throwable) {
                $failure = $failure->withRecommendation(
                    $failure->recommendation.' Automatic cleanup of the tool-owned path also failed; inspect that path without deleting foreign files.',
                );
            }
        }

        try {
            $this->maintenance->end($maintenanceEnabled);
        } catch (Throwable) {
            if ($failure === null) {
                $failure = $this->phaseFailure('MAINTENANCE');
            } else {
                $failure = $failure->withRecommendation(
                    $failure->recommendation.' Maintenance cleanup also failed; manually verify that the application is not left in maintenance mode.',
                );
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }

    private function classifyFailure(Throwable $exception, string $phase): BackupFailure
    {
        if ($exception instanceof DumpException) {
            $details = $exception->safeDetails();

            return new BackupFailure(
                $exception->failureCode,
                'DATABASE_DUMP',
                $exception->processExitCode,
                $exception->recommendation,
                isset($details['binary']) ? ['binary' => (string) $details['binary']] : [],
            );
        }
        if ($exception instanceof BackupFailure) {
            return $exception;
        }

        return $this->phaseFailure($phase);
    }

    private function phaseFailure(string $phase): BackupFailure
    {
        [$code, $recommendation] = match ($phase) {
            'DATABASE_DUMP' => ['DUMP_FAILED_UNKNOWN', 'Run backup:diagnose --json and review its sanitized schema and data probe results.'],
            'PRIVATE_ARCHIVE' => ['PRIVATE_ARCHIVE_FAILED', 'Verify private storage readability and backup filesystem capacity without changing source files.'],
            'METADATA' => ['METADATA_FAILED', 'Verify read-only schema metadata access and staging filesystem availability.'],
            'MANIFEST' => ['MANIFEST_FAILED', 'Verify staging filesystem integrity and rerun only after the failed target and staging are absent.'],
            'VERIFY' => ['VERIFY_FAILED', 'Inspect the sanitized verifier result and do not use or publish the incomplete backup.'],
            'FINALIZE' => ['FINALIZE_FAILED', 'Use a new empty target on the same private filesystem and verify atomic rename support.'],
            'MAINTENANCE' => ['MAINTENANCE_FAILED', 'Manually verify application maintenance state before any further backup attempt.'],
            default => ['BACKUP_FAILED_UNKNOWN', 'Stop further attempts and inspect application logs through the restricted operator channel.'],
        };

        $context = $phase === 'VERIFY' ? ['verification_code' => 'VERIFY_INTERNAL_ERROR'] : [];

        return new BackupFailure($code, $phase, null, $recommendation, $context);
    }

    private function failForTesting(string $phase): void
    {
        if ($this->isTestingProcess() && config('backups.testing_fail_phase') === $phase) {
            throw new RuntimeException('phase-failure-canary-must-not-be-rendered');
        }
    }

    private function databaseState(): array
    {
        if (! Schema::hasTable('migrations')) {
            throw new RuntimeException('Migrations repository is missing.');
        }
        $migrations = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
        $counts = [];
        foreach ((array) config('backups.critical_tables', []) as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('A critical backup table is missing.');
            }
            $counts[$table] = DB::table($table)->count();
        }

        return [
            'schema' => ['migrations' => $migrations, 'fingerprint' => hash('sha256', json_encode($migrations, JSON_UNESCAPED_SLASHES))],
            'critical_counts' => $counts,
        ];
    }

    private function verifyDocumentLinks(array $entries): void
    {
        $available = collect($entries)->keyBy('path');
        $referenced = [];
        foreach ([['documents', 'storage_name'], ['message_attachments', 'storage_path']] as [$table, $column]) {
            DB::table($table)->select([$column, 'checksum_sha256'])->orderBy('id')->each(function ($row) use ($column, $available, &$referenced): void {
                $path = (string) $row->{$column};
                $entry = $available->get($path);
                if (! is_array($entry) || ! hash_equals((string) $row->checksum_sha256, $entry['sha256'])) {
                    throw $this->verificationFailure('PRIVATE_INVENTORY_MISMATCH', 'private-storage.tar', 'A database-referenced private file is missing or has a different checksum.');
                }
                $referenced[] = $path;
            });
        }
        $all = $available->keys()->sort()->values()->all();
        sort($referenced);
        if ($all !== $referenced) {
            throw $this->verificationFailure('PRIVATE_INVENTORY_MISMATCH', 'private-storage.tar', 'Private storage contains a file outside the documented backup inventory policy.');
        }
    }

    private function manifest(array $metadata, string $databaseVersion, array $archiveEntries, string $root): array
    {
        $connection = (string) config('database.default');
        $database = (array) config("database.connections.{$connection}", []);

        return [
            'format_version' => (int) config('backups.format_version', 1),
            'backup_id' => $metadata['backup_id'],
            'created_at_utc' => $metadata['created_at_utc'],
            'environment' => app()->environment(),
            'status' => 'INCOMPLETE',
            'git_commit' => $metadata['git_commit'],
            'schema' => $metadata['schema'],
            'database' => [
                'engine' => $connection === 'sqlite' ? 'sqlite' : 'mariadb',
                'version' => $databaseVersion,
                'source_identity_sha256' => $this->sourceIdentity($connection, $database),
                'critical_counts' => $metadata['critical_counts'],
                'logical_objects' => $metadata['database_dump']['logical_objects'],
                'dump_options' => $metadata['database_dump']['options'],
            ],
            'private_storage' => $metadata['private_storage'],
            'components' => [
                'database' => true, 'private_storage' => true, 'schema_metadata' => true,
                'deployed_revision' => true, 'runtime_versions' => true,
                'apache_php_configuration' => false, 'webserver_cron_export' => false,
                'windows_task_scheduler_export' => false, 'connector_config_state' => false,
                'protected_secret_escrow' => false,
            ],
            'runtime' => $metadata['runtime'],
            'parts' => [
                $this->part($root, 'database.sql', 'database-dump'),
                $this->part($root, 'private-storage.tar', 'private-archive') + ['entries' => $archiveEntries],
                $this->part($root, 'metadata.json', 'metadata'),
            ],
            'off_server' => ['confirmed' => false, 'confirmed_at_utc' => null],
            'restore_test' => ['status' => 'NOT_RUN', 'completed_at_utc' => null],
        ];
    }

    private function part(string $root, string $path, string $kind): array
    {
        return ['name' => $path, 'kind' => $kind, 'path' => $path, 'size' => filesize($root.DIRECTORY_SEPARATOR.$path), 'sha256' => hash_file('sha256', $root.DIRECTORY_SEPARATOR.$path)];
    }

    private function databaseVersion(): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return (string) DB::selectOne('select sqlite_version() version')->version;
        }

        return (string) DB::selectOne('select version() version')->version;
    }

    private function sourceIdentity(string $connection, array $database): string
    {
        $override = $this->isTestingProcess() ? config('backups.testing_source_identity_sha256') : null;
        if (is_string($override) && preg_match('/\A[0-9a-f]{64}\z/', $override) === 1) {
            return $override;
        }

        return hash('sha256', implode('|', [
            strtolower($connection), strtolower((string) ($database['host'] ?? '')),
            (string) ($database['port'] ?? ''), (string) ($database['database'] ?? ''),
        ]));
    }

    private function gitCommit(): string
    {
        $override = $this->isTestingProcess() ? config('backups.testing_git_commit') : null;
        if (is_string($override) && preg_match('/\A[0-9a-f]{40}\z/', $override) === 1) {
            return $override;
        }
        $process = new Process(['git', 'rev-parse', '--verify', 'HEAD'], base_path());
        $process->setTimeout(10);
        $process->run();
        $commit = trim($process->getOutput());
        if (! $process->isSuccessful() || preg_match('/\A[0-9a-f]{40}\z/', $commit) !== 1) {
            throw new RuntimeException('Unable to resolve deployed Git commit.');
        }

        return $commit;
    }

    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json.PHP_EOL, LOCK_EX) === false || ! chmod($path, 0600)) {
            throw new RuntimeException('Unable to write sanitized backup metadata.');
        }
    }

    private function writeJsonAtomically(string $path, array $data): void
    {
        $temporary = dirname($path).DIRECTORY_SEPARATOR.'.manifest-'.bin2hex(random_bytes(12)).'.tmp';
        try {
            $this->writeJson($temporary, $data);
            if (! rename($temporary, $path)) {
                throw new RuntimeException('Unable to publish COMPLETE manifest atomically.');
            }
        } finally {
            @unlink($temporary);
        }
    }

    private function verifyBeforeComplete(array $manifest, string $staging): void
    {
        $candidate = $staging.DIRECTORY_SEPARATOR.'.manifest-incomplete-'.bin2hex(random_bytes(12)).'.json';
        try {
            $this->writeJson($candidate, $manifest);
            $verification = $this->verifier->verifyCandidate(new BackupCandidateManifest($candidate, $staging));
            if (! $verification->valid) {
                throw $this->verificationFailureFromResult($verification);
            }
        } finally {
            @unlink($candidate);
        }
    }

    private function verificationFailureFromResult(BackupVerification $verification): BackupFailure
    {
        return $this->verificationFailure(
            $verification->verificationCode ?? 'VERIFY_INTERNAL_ERROR',
            $verification->component,
            'The incomplete backup failed internal verification; do not publish or reuse its staging directory.',
        );
    }

    private function verificationFailure(string $verificationCode, ?string $component, string $recommendation): BackupFailure
    {
        return new BackupFailure(
            'VERIFY_FAILED',
            'VERIFY',
            null,
            $recommendation,
            array_filter([
                'verification_code' => $verificationCode,
                'component' => $component,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    private function isTestingProcess(): bool
    {
        return ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null) === 'testing';
    }
}
