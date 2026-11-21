<?php

namespace Tests\Support;

use App\Backup\Contracts\PrivateStorageArchiver;
use App\Backup\PrivateStoragePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class BackupFixture
{
    private function __construct(private readonly string $root) {}

    /** @param array<string,string> $privateFiles source path => contents */
    public static function create(array $privateFiles = [], array $overrides = []): self
    {
        if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null) !== 'testing') {
            throw new RuntimeException('Backup fixtures are allowed only in testing.');
        }
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'portal-phase8b-backup-'.Str::uuid();
        if (! mkdir($root, 0700)) {
            throw new RuntimeException('Unable to create backup fixture.');
        }
        file_put_contents($root.'/database.sql', '-- isolated fixture, not a production dump');
        $source = $root.'/.private-source';
        mkdir($source, 0700);
        foreach ($privateFiles as $sourcePath => $contents) {
            $path = $source.'/'.$sourcePath;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            file_put_contents($path, $contents);
            chmod($path, 0600);
        }
        $entries = app(PrivateStorageArchiver::class)->archive($source, $root.'/private-storage.tar');
        File::deleteDirectory($source);

        $migrations = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
        $counts = [];
        foreach ((array) config('backups.critical_tables') as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $backupId = 'fixture-'.Str::uuid();
        $createdAt = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $gitCommit = str_repeat('a', 40);
        $schema = ['migrations' => $migrations, 'fingerprint' => hash('sha256', json_encode($migrations))];
        $dumpMetadata = [
            'logical_objects' => ['triggers' => 0, 'routines' => 0, 'events' => 0],
            'options' => [
                'single_transaction' => true, 'quick' => true, 'skip_lock_tables' => true,
                'no_tablespaces' => true, 'triggers' => false, 'routines' => false, 'events' => false,
            ],
        ];
        $privateStorage = [
            'excluded_operational_artifacts' => app(PrivateStoragePolicy::class)->excludedOperationalArtifacts(),
        ];
        $metadata = [
            'backup_id' => $backupId, 'created_at_utc' => $createdAt, 'git_commit' => $gitCommit,
            'runtime' => ['portal_php' => '8.5', 'mariadb' => 'fixture', 'connector_php' => '7.4.33', 'connector_os' => 'Windows Server 2012 R2'],
            'schema' => $schema, 'critical_counts' => $counts, 'database_dump' => $dumpMetadata,
            'private_storage' => $privateStorage,
            'private_file_count' => count($entries),
        ];
        file_put_contents($root.'/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($root.'/metadata.json', 0600);
        $parts = [
            self::part($root, 'database.sql', 'database-dump'),
            self::part($root, 'private-storage.tar', 'private-archive') + ['entries' => $entries],
            self::part($root, 'metadata.json', 'metadata'),
        ];
        $manifest = [
            'format_version' => 1,
            'backup_id' => $backupId,
            'created_at_utc' => $createdAt,
            'environment' => 'production',
            'status' => 'COMPLETE',
            'git_commit' => $gitCommit,
            'schema' => $schema,
            'database' => [
                'engine' => 'mariadb', 'version' => 'fixture',
                'source_identity_sha256' => hash('sha256', 'production-source-fixture'),
                'critical_counts' => $counts,
                'logical_objects' => $dumpMetadata['logical_objects'],
                'dump_options' => $dumpMetadata['options'],
            ],
            'private_storage' => $privateStorage,
            'components' => [
                'database' => true, 'private_storage' => true, 'schema_metadata' => true,
                'deployed_revision' => true, 'runtime_versions' => true,
                'apache_php_configuration' => false, 'webserver_cron_export' => false,
                'windows_task_scheduler_export' => false, 'connector_config_state' => false,
                'protected_secret_escrow' => false,
            ],
            'runtime' => $metadata['runtime'],
            'parts' => $parts,
            'off_server' => ['confirmed' => false, 'confirmed_at_utc' => null],
            'restore_test' => ['status' => 'NOT_RUN', 'completed_at_utc' => null],
        ];
        $manifest = array_replace_recursive($manifest, $overrides);
        file_put_contents($root.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return new self($root);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function manifest(): array
    {
        return json_decode((string) file_get_contents($this->root.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function rewrite(array $manifest): void
    {
        file_put_contents($this->root.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function cleanup(): void
    {
        if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null) !== 'testing') {
            throw new RuntimeException('Backup fixture cleanup is allowed only in testing.');
        }
        $resolved = realpath($this->root);
        $prefix = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'portal-phase8b-backup-';
        if ($resolved === false || ! str_starts_with($resolved, $prefix)) {
            throw new RuntimeException('Refusing to clean an unverified backup fixture.');
        }
        File::deleteDirectory($resolved);
    }

    private static function part(string $root, string $path, string $kind): array
    {
        return [
            'name' => str_replace('/', '-', $path),
            'kind' => $kind,
            'path' => $path,
            'size' => filesize($root.'/'.$path),
            'sha256' => hash_file('sha256', $root.'/'.$path),
        ];
    }
}
