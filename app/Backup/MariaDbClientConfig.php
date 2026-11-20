<?php

namespace App\Backup;

use RuntimeException;

final class MariaDbClientConfig
{
    /** @return array<string, mixed> */
    public function databaseConfiguration(): array
    {
        $override = app()->environment('testing') ? config('backups.testing_database_configuration') : null;
        $database = is_array($override)
            ? $override
            : (array) config('database.connections.'.config('database.default'), []);
        if (($database['driver'] ?? null) !== 'mysql' || blank($database['database'] ?? null)) {
            throw new DumpException('DUMP_CONNECTION_FAILED', 'configuration', null, 'mariadb-dump', 'Configure a MariaDB connection before running backup diagnostics or creation.');
        }

        return $database;
    }

    /** @param array<string, mixed> $database */
    public function create(string $directory, array $database): string
    {
        $path = $directory.DIRECTORY_SEPARATOR.'.mariadb-client-'.bin2hex(random_bytes(12)).'.cnf';
        $socket = (string) ($database['unix_socket'] ?? '');
        $values = $socket !== ''
            ? ['protocol' => 'socket', 'socket' => $socket]
            : ['protocol' => 'tcp', 'host' => $database['host'] ?? null, 'port' => $database['port'] ?? null];
        $values += ['user' => $database['username'] ?? null, 'password' => $database['password'] ?? null];
        foreach ($values as $value) {
            if (is_string($value) && preg_match('/[\r\n\0]/', $value) === 1) {
                throw new RuntimeException('Database client configuration contains an invalid control character.');
            }
        }
        $lines = ['[client]'];
        foreach ($values as $key => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = $key.'="'.addcslashes((string) $value, '\\"').'"';
            }
        }
        if (file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL, LOCK_EX) === false || ! chmod($path, 0600)) {
            @unlink($path);
            throw new DumpException('DUMP_OUTPUT_WRITE_FAILED', 'credentials_file', null, 'mariadb-dump', 'Use a writable private staging parent and verify filesystem permissions.');
        }

        return $path;
    }
}
