<?php

namespace App\Backup;

use App\Backup\Contracts\DatabaseDumpRunner;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class MariaDbDumpRunner implements DatabaseDumpRunner
{
    public function dump(string $outputPath, string $credentialsDirectory): void
    {
        $connection = (string) config('database.default');
        $database = (array) config("database.connections.{$connection}", []);
        if (($database['driver'] ?? null) !== 'mysql' || blank($database['database'] ?? null)) {
            throw new RuntimeException('Production backup requires a configured MariaDB connection.');
        }

        $credentials = $credentialsDirectory.DIRECTORY_SEPARATOR.'.mariadb-client-'.bin2hex(random_bytes(12)).'.cnf';
        $this->writeCredentials($credentials, $database);
        try {
            $process = new Process([
                (string) config('backups.dump_binary', 'mariadb-dump'),
                '--defaults-extra-file='.$credentials,
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                '--routines',
                '--triggers',
                '--events',
                '--hex-blob',
                '--default-character-set=utf8mb4',
                '--result-file='.$outputPath,
                (string) $database['database'],
            ]);
            $process->setTimeout(max(60, (int) config('backups.dump_timeout_seconds', 3600)));
            $process->run();
            if (! $process->isSuccessful() || ! is_file($outputPath) || filesize($outputPath) < 1) {
                throw new RuntimeException('Database dump failed; database tool output was suppressed.');
            }
            if (! chmod($outputPath, 0600)) {
                throw new RuntimeException('Database dump permissions could not be secured.');
            }
        } catch (Throwable $exception) {
            @unlink($outputPath);
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('Database dump failed; database tool output was suppressed.');
        } finally {
            if (is_file($credentials)) {
                @unlink($credentials);
            }
        }
    }

    private function writeCredentials(string $path, array $database): void
    {
        $values = [
            'host' => $database['host'] ?? null,
            'port' => $database['port'] ?? null,
            'user' => $database['username'] ?? null,
            'password' => $database['password'] ?? null,
            'socket' => $database['unix_socket'] ?? null,
        ];
        foreach ($values as $value) {
            if (is_string($value) && preg_match('/[\r\n]/', $value) === 1) {
                throw new RuntimeException('Database credential configuration is invalid.');
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
            throw new RuntimeException('Unable to create protected database credentials file.');
        }
    }
}
