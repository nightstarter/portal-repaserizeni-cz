<?php

namespace App\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PharData;
use RuntimeException;
use Throwable;

final class RestoreCheckService
{
    public function __construct(
        private readonly BackupManifestVerifier $verifier,
        private readonly BackupPathGuard $paths,
    ) {}

    public function run(string $backupPath, string $target): array
    {
        $this->assertEnvironment();
        $this->assertDatabaseTarget();
        $this->assertInactiveIntegrations();

        $verification = $this->verifier->verify($backupPath);
        if (! $verification->valid || $verification->manifest === null || $verification->root === null) {
            throw new RuntimeException('Backup manifest or checksums are invalid.');
        }
        if (hash_equals($verification->manifest['database']['source_identity_sha256'], $this->targetDatabaseFingerprint())) {
            throw new RuntimeException('Restore target matches the backup source database identity.');
        }

        $targetState = $this->paths->emptyRestoreTarget($target);
        $copied = [];
        $createdDirectories = [];
        try {
            $this->extractPrivateArchive($verification->manifest, $verification->root, $targetState['path'], $copied, $createdDirectories);
            $this->copyPrivateParts($verification->manifest, $verification->root, $targetState['path'], $copied, $createdDirectories);
            $checks = $this->verifyDatabaseAndStorage($verification->manifest, $targetState['path'], $copied);

            return [
                'status' => 'OK',
                'backup_id' => $verification->manifest['backup_id'],
                'target' => $targetState['path'],
                'copied_private_files' => count($copied),
                'checks' => $checks,
                'target_created_by_tool' => $targetState['created'],
            ];
        } catch (Throwable $exception) {
            $this->cleanupOwnFiles($targetState['path'], $targetState['created'], $copied, $createdDirectories);
            throw $exception;
        }
    }

    private function extractPrivateArchive(array $manifest, string $backupRoot, string $target, array &$copied, array &$createdDirectories): void
    {
        $part = collect($manifest['parts'])->firstWhere('kind', 'private-archive');
        if (! is_array($part)) {
            return;
        }
        $entries = $part['entries'] ?? [];
        foreach ($entries as $entry) {
            $path = $target.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['path']);
            $copied[] = $path;
            $directory = dirname($path);
            while ($directory !== $target && $this->paths->isWithin($directory, $target)) {
                $createdDirectories[] = $directory;
                $directory = dirname($directory);
            }
        }
        try {
            $archive = new PharData($backupRoot.DIRECTORY_SEPARATOR.$part['path']);
            if (! $archive->extractTo($target, null, false)) {
                throw new RuntimeException;
            }
        } catch (Throwable) {
            throw new RuntimeException('Private storage archive extraction failed.');
        }
        foreach ($entries as $entry) {
            $path = $target.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['path']);
            if (! is_file($path) || ! chmod($path, octdec($entry['mode']))) {
                throw new RuntimeException('Extracted private file is missing or has invalid permissions.');
            }
            $failAfter = $this->isTestingProcess() ? config('backups.testing_fail_after_copied_parts') : null;
            if (is_numeric($failAfter) && count($copied) >= (int) $failAfter) {
                throw new RuntimeException('Simulated restore interruption.');
            }
        }
    }

    private function assertEnvironment(): void
    {
        if (! app()->environment(['testing', 'restore-test'])) {
            throw new RuntimeException('Restore check requires APP_ENV=testing or APP_ENV=restore-test.');
        }
    }

    private function assertDatabaseTarget(): void
    {
        $connection = (string) config('database.default');
        $current = (array) config("database.connections.{$connection}", []);
        $production = (array) config('backups.production_database', []);

        if ($connection !== 'sqlite' && app()->environment('testing')) {
            throw new RuntimeException('Testing restore checks require SQLite.');
        }
        if ($connection === 'sqlite' && ($current['database'] ?? null) !== ':memory:' && app()->environment('testing')) {
            throw new RuntimeException('Testing restore checks require SQLite :memory:.');
        }
        if (! app()->environment('testing') && blank($production['database'] ?? null)) {
            throw new RuntimeException('Production database identity must be configured for restore-test guard.');
        }
        if (filled($production['database'] ?? null)
            && strtolower((string) ($current['host'] ?? '')) === strtolower((string) ($production['host'] ?? ''))
            && (string) ($current['port'] ?? '') === (string) ($production['port'] ?? '')
            && (string) ($current['database'] ?? '') === (string) $production['database']) {
            throw new RuntimeException('Restore target matches the production database identity.');
        }
    }

    private function targetDatabaseFingerprint(): string
    {
        $connection = (string) config('database.default');
        $current = (array) config("database.connections.{$connection}", []);

        return hash('sha256', implode('|', [
            strtolower($connection),
            strtolower((string) ($current['host'] ?? '')),
            (string) ($current['port'] ?? ''),
            (string) ($current['database'] ?? ''),
        ]));
    }

    private function assertInactiveIntegrations(): void
    {
        $safe = ! config('integrations.legacy_shop.enabled')
            && ! config('discussion_notifications.enabled')
            && ! config('buyback.customer_email_enabled')
            && ! config('integrations.pohoda_bridge.enabled')
            && count((array) config('integrations.pohoda_bridge.allowed_command_types', [])) === 0
            && config('operations.product_repricing') === 'DEFERRED_EXTERNAL_SYSTEM';
        if (! $safe) {
            throw new RuntimeException('Restore-test integrations are not safely disabled.');
        }
    }

    private function copyPrivateParts(array $manifest, string $backupRoot, string $target, array &$copied, array &$createdDirectories): void
    {
        $failAfter = $this->isTestingProcess() ? config('backups.testing_fail_after_copied_parts') : null;
        foreach ($manifest['parts'] as $part) {
            if (($part['kind'] ?? null) !== 'private-file') {
                continue;
            }
            $sourcePath = $part['source_path'] ?? null;
            if (! is_string($sourcePath) || ! $this->safeRelative($sourcePath)) {
                throw new RuntimeException('Private backup part has an unsafe restore path.');
            }
            $source = $backupRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $part['path']);
            $destination = $target.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $sourcePath);
            $parent = dirname($destination);
            $this->createTargetDirectories($parent, $target, $createdDirectories);
            if (file_exists($destination)) {
                throw new RuntimeException('Duplicate private restore destination.');
            }
            if (! copy($source, $destination)) {
                throw new RuntimeException('Unable to copy a private backup part.');
            }
            $copied[] = $destination;
            $mode = $part['mode'] ?? '0600';
            if (! is_string($mode) || preg_match('/\A0[0-7]{3}\z/', $mode) !== 1 || ! chmod($destination, octdec($mode))) {
                throw new RuntimeException('Unable to preserve private file permissions.');
            }
            if (is_numeric($failAfter) && count($copied) >= (int) $failAfter) {
                throw new RuntimeException('Simulated restore interruption.');
            }
        }
    }

    private function createTargetDirectories(string $directory, string $target, array &$createdDirectories): void
    {
        $relative = substr($directory, strlen($target) + 1);
        if ($relative === false || $relative === '') {
            return;
        }
        $current = $target;
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            if (! is_dir($current)) {
                if (! mkdir($current, 0700)) {
                    throw new RuntimeException('Unable to prepare restore target directory.');
                }
                $createdDirectories[] = $current;
            }
        }
    }

    private function verifyDatabaseAndStorage(array $manifest, string $target, array $copied): array
    {
        if (! Schema::hasTable('migrations')) {
            throw new RuntimeException('Restored database has no migrations table.');
        }
        $actualMigrations = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
        $expectedMigrations = $manifest['schema']['migrations'];
        sort($expectedMigrations);
        if ($actualMigrations !== $expectedMigrations) {
            throw new RuntimeException('Restored migration set does not match the manifest.');
        }

        $counts = [];
        foreach ((array) config('backups.critical_tables') as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('A critical restored table is missing.');
            }
            $counts[$table] = DB::table($table)->count();
            if ($counts[$table] !== $manifest['database']['critical_counts'][$table]) {
                throw new RuntimeException('A critical restored table count does not match.');
            }
        }

        $this->verifyForeignKeys();
        $this->verifyUniqueIndexes();
        $referenced = $this->verifyReferencedFiles($target);
        $relativeCopied = array_map(fn (string $path): string => str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($target) + 1)), $copied);
        sort($relativeCopied);
        sort($referenced);
        if ($relativeCopied !== $referenced) {
            throw new RuntimeException('Private storage contains missing or orphaned files.');
        }

        return [
            'migrations' => 'OK',
            'critical_table_counts' => 'OK',
            'foreign_keys' => 'OK',
            'unique_indexes' => 'OK',
            'document_files' => 'OK',
            'orphan_policy' => 'OK',
            'integrations_disabled' => 'OK',
        ];
    }

    private function verifyForeignKeys(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            if (DB::select('PRAGMA foreign_key_check') !== []) {
                throw new RuntimeException('Foreign key verification failed.');
            }

            return;
        }
        $database = DB::connection()->getDatabaseName();
        $count = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', $database)->count();
        if ($count < 1) {
            throw new RuntimeException('No foreign key constraints were found.');
        }
    }

    private function verifyUniqueIndexes(): void
    {
        foreach ([
            ['organizations', ['code']],
            ['users', ['email']],
            ['orders', ['order_no']],
            ['documents', ['public_token']],
            ['shipments', ['public_number']],
        ] as [$table, $columns]) {
            $indexes = Schema::getIndexes($table);
            $found = collect($indexes)->contains(fn (array $index): bool => (bool) ($index['unique'] ?? false) && ($index['columns'] ?? []) === $columns);
            if (! $found) {
                throw new RuntimeException('A required unique index is missing.');
            }
        }
    }

    /** @return list<string> */
    private function verifyReferencedFiles(string $target): array
    {
        $referenced = [];
        foreach ([['documents', 'storage_name'], ['message_attachments', 'storage_path']] as [$table, $column]) {
            DB::table($table)->select([$column, 'checksum_sha256'])->orderBy('id')->each(function ($row) use ($target, $column, &$referenced): void {
                $relative = (string) $row->{$column};
                if (! $this->safeRelative($relative)) {
                    throw new RuntimeException('Database contains an unsafe private storage path.');
                }
                $path = $target.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (! is_file($path) || ! hash_equals((string) $row->checksum_sha256, hash_file('sha256', $path))) {
                    throw new RuntimeException('A database document is missing or has an invalid checksum.');
                }
                $referenced[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            });
        }

        return $referenced;
    }

    private function cleanupOwnFiles(string $target, bool $createdTarget, array $copied, array $createdDirectories): void
    {
        if ($createdTarget) {
            File::deleteDirectory($target);

            return;
        }
        foreach (array_reverse($copied) as $path) {
            @unlink($path);
        }
        usort($createdDirectories, fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach (array_unique($createdDirectories) as $directory) {
            if (is_dir($directory) && (scandir($directory) ?: []) === ['.', '..']) {
                @rmdir($directory);
            }
        }
    }

    private function safeRelative(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, "\0")
            && preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) !== 1
            && preg_match('/(^|[\\\\\/])\.\.([\\\\\/]|$)/', $path) !== 1;
    }

    private function isTestingProcess(): bool
    {
        return ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null) === 'testing';
    }
}
