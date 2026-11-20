<?php

namespace App\Backup;

use App\Backup\Contracts\DumpProcessRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

final class BackupDiagnoser
{
    public function __construct(
        private readonly BackupPathGuard $paths,
        private readonly DumpProcessRunner $processes,
        private readonly MariaDbClientConfig $credentials,
        private readonly MariaDbDumpCommand $commands,
        private readonly DatabaseObjectInspector $objects,
        private readonly DumpFailureClassifier $failures,
    ) {}

    /** @return array{status:string,checks:list<array<string,mixed>>} */
    public function diagnose(): array
    {
        $checks = [];
        $sandbox = null;
        $credentials = null;
        $binary = null;
        $database = null;
        $objects = null;
        $clientVersion = null;
        $serverVersion = null;
        try {
            try {
                $binary = $this->commands->binary();
                $checks[] = $this->check('dump_binary', 'OK', ['binary' => basename($binary)]);
                $version = $this->processes->run([$binary, '--version'], null, 10);
                if ($version->exitCode !== 0) {
                    throw $this->failures->fromResult($version, $binary, ['triggers' => 0, 'routines' => 0, 'events' => 0]);
                }
                $clientVersion = $this->safeVersion($version->stdout);
                $checks[] = $this->check('dump_client_version', 'OK', ['version' => $clientVersion]);
            } catch (DumpException $exception) {
                $checks[] = $this->errorCheck('dump_binary', $exception);
            }

            try {
                $parent = (string) config('backups.diagnose_parent', sys_get_temp_dir());
                $candidate = rtrim($parent, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'portal-backup-diagnose-'.Str::uuid();
                $sandbox = $this->paths->newBackupTarget($candidate);
                if (! is_writable(dirname($sandbox)) || ! mkdir($sandbox, 0700)) {
                    throw new \RuntimeException;
                }
                $checks[] = $this->check('staging_parent', 'OK', ['writable' => true, 'protected_path_overlap' => false]);
            } catch (Throwable) {
                $checks[] = $this->check('staging_parent', 'ERROR', ['code' => 'DUMP_OUTPUT_WRITE_FAILED', 'recommendation' => 'Configure a canonical writable diagnostic parent outside the repository, public and application storage.']);
            }

            try {
                $database = $this->credentials->databaseConfiguration();
                if (! is_string($sandbox)) {
                    throw new DumpException('DUMP_OUTPUT_WRITE_FAILED', 'credentials_file', null, $binary === null ? 'mariadb-dump' : basename($binary), 'Fix the diagnostic staging parent before testing credentials file creation.');
                }
                $credentials = $this->credentials->create($sandbox, $database);
                $mode = substr(sprintf('%o', fileperms($credentials)), -3);
                if ($mode !== '600') {
                    throw new DumpException('DUMP_OUTPUT_WRITE_FAILED', 'credentials_file', null, $binary === null ? 'mariadb-dump' : basename($binary), 'The temporary client configuration must have mode 0600.');
                }
                $checks[] = $this->check('credentials_file', 'OK', ['mode' => '0600', 'cleanup' => 'scheduled']);
                $checks[] = $this->check('database_transport', 'OK', [
                    'mode' => blank($database['unix_socket'] ?? null) ? 'tcp' : 'socket',
                    'tls_explicitly_configured' => $this->tlsConfigured($database),
                ]);
            } catch (DumpException $exception) {
                $checks[] = $this->errorCheck('credentials_file', $exception);
            }

            try {
                DB::connection()->getPdo();
                $driver = DB::connection()->getDriverName();
                $version = $driver === 'sqlite'
                    ? (string) DB::selectOne('select sqlite_version() version')->version
                    : (string) DB::selectOne('select version() version')->version;
                $checks[] = $this->check('pdo_connection', 'OK', ['driver' => $driver]);
                $serverVersion = $this->safeVersion($version);
                $checks[] = $this->check('database_server_version', 'OK', ['version' => $serverVersion]);
            } catch (Throwable) {
                $checks[] = $this->check('pdo_connection', 'ERROR', ['code' => 'DUMP_CONNECTION_FAILED', 'recommendation' => 'Verify application database reachability without changing credentials or privileges.']);
            }

            if (is_string($clientVersion) && is_string($serverVersion) && DB::connection()->getDriverName() === 'mysql') {
                $compatible = $this->majorMinor($clientVersion) === $this->majorMinor($serverVersion);
                $checks[] = $this->check('client_server_compatibility', $compatible ? 'OK' : 'WARN', [
                    'same_major_minor' => $compatible,
                    'recommendation' => $compatible ? null : 'Use a dump client with the same MariaDB major.minor version as the server when practical.',
                ]);
            }

            try {
                $objects = $this->objects->inspect();
                $checks[] = $this->check('database_logical_objects', 'OK', [
                    'counts' => $objects,
                    'special_dump_options_required' => array_sum($objects) > 0,
                ]);
            } catch (Throwable) {
                $checks[] = $this->check('database_logical_objects', 'ERROR', ['code' => 'DUMP_PERMISSION_DENIED', 'recommendation' => 'Allow read-only information_schema visibility for the portal database; do not print SHOW GRANTS.']);
            }

            if (is_string($binary) && is_string($credentials) && is_string($sandbox) && is_array($database) && is_array($objects)) {
                try {
                    $probe = $sandbox.DIRECTORY_SEPARATOR.'schema-probe.sql';
                    $command = $this->commands->build($binary, $credentials, (string) $database['database'], $objects, true);
                    $result = $this->processes->run($command, $probe, min(120, max(10, (int) config('backups.diagnose_timeout_seconds', 60))));
                    if ($result->exitCode !== 0) {
                        throw $this->failures->fromResult($result, $binary, $objects);
                    }
                    if (! is_file($probe) || filesize($probe) < 1) {
                        throw new DumpException('DUMP_OUTPUT_WRITE_FAILED', 'schema_probe', $result->exitCode, basename($binary), 'Verify diagnostic staging filesystem availability.');
                    }
                    $checks[] = $this->check('schema_only_probe', 'OK', [
                        'rows_exported' => false,
                        'options' => $this->commands->safeOptionMetadata($objects),
                    ]);
                } catch (DumpException $exception) {
                    $checks[] = $this->errorCheck('schema_only_probe', $exception);
                }
            } else {
                $checks[] = $this->check('schema_only_probe', 'ERROR', ['code' => 'DUMP_PROCESS_START_FAILED', 'recommendation' => 'Resolve preceding diagnostic errors before running the schema-only probe.']);
            }
        } finally {
            if (is_string($credentials)) {
                @unlink($credentials);
            }
            if (is_string($sandbox) && is_dir($sandbox)) {
                File::deleteDirectory($sandbox);
            }
        }

        return [
            'status' => collect($checks)->contains(fn (array $check): bool => $check['status'] === 'ERROR') ? 'ERROR' : 'OK',
            'checks' => $checks,
        ];
    }

    private function check(string $name, string $status, array $details): array
    {
        return ['name' => $name, 'status' => $status] + $details;
    }

    private function errorCheck(string $name, DumpException $exception): array
    {
        $details = $exception->safeDetails();
        unset($details['status']);

        return $this->check($name, 'ERROR', $details);
    }

    private function safeVersion(string $value): string
    {
        if (preg_match('/\bDistrib\s+(\d+\.\d+(?:\.\d+)?)(?:-MariaDB)?\b/i', $value, $match) === 1) {
            return $match[1].(stripos($value, 'mariadb') !== false ? '-MariaDB' : '');
        }
        if (preg_match('/\b(\d+\.\d+(?:\.\d+)?)(?:-MariaDB)?\b/i', $value, $match) === 1) {
            return $match[1].(stripos($value, 'mariadb') !== false ? '-MariaDB' : '');
        }

        return 'unrecognized';
    }

    private function majorMinor(string $version): string
    {
        return preg_match('/\A(\d+\.\d+)/', $version, $match) === 1 ? $match[1] : 'unrecognized';
    }

    /** @param array<string,mixed> $database */
    private function tlsConfigured(array $database): bool
    {
        return collect((array) ($database['options'] ?? []))->filter()->isNotEmpty();
    }
}
