<?php

namespace Tests\Support;

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
        file_put_contents($root.'/operations.age', 'externally encrypted fixture placeholder');

        $parts = [
            self::part($root, 'database.sql', 'database-dump'),
            self::part($root, 'operations.age', 'operations-config') + ['encrypted' => true],
        ];
        foreach ($privateFiles as $sourcePath => $contents) {
            $path = 'private/'.hash('sha256', $sourcePath).'.bin';
            if (! is_dir(dirname($root.'/'.$path))) {
                mkdir(dirname($root.'/'.$path), 0700, true);
            }
            file_put_contents($root.'/'.$path, $contents);
            chmod($root.'/'.$path, 0600);
            $parts[] = self::part($root, $path, 'private-file') + ['source_path' => $sourcePath, 'mode' => '0600'];
        }

        $migrations = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
        $counts = [];
        foreach ((array) config('backups.critical_tables') as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $manifest = [
            'format_version' => 1,
            'backup_id' => 'fixture-'.Str::uuid(),
            'created_at_utc' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'environment' => 'production',
            'status' => 'COMPLETE',
            'git_commit' => str_repeat('a', 40),
            'schema' => ['migrations' => $migrations, 'fingerprint' => hash('sha256', json_encode($migrations))],
            'database' => [
                'engine' => 'mariadb', 'version' => 'fixture',
                'source_identity_sha256' => hash('sha256', 'production-source-fixture'),
                'critical_counts' => $counts,
            ],
            'components' => array_fill_keys([
                'database', 'private_storage', 'apache_php_configuration', 'webserver_cron_export',
                'windows_task_scheduler_export', 'connector_config_state', 'deployed_revision',
                'runtime_versions', 'protected_secret_escrow',
            ], true),
            'runtime' => ['portal_php' => '8.5', 'connector_php' => '7.4.33', 'connector_os' => 'Windows Server 2012 R2'],
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
