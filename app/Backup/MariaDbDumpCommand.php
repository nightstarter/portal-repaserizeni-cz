<?php

namespace App\Backup;

use Symfony\Component\Process\ExecutableFinder;

final class MariaDbDumpCommand
{
    public function binary(): string
    {
        $configured = (string) config('backups.dump_binary', 'mariadb-dump');
        $resolved = str_contains($configured, DIRECTORY_SEPARATOR)
            ? realpath($configured)
            : (new ExecutableFinder)->find($configured);
        if (! is_string($resolved) || ! is_file($resolved) || ! is_executable($resolved)) {
            throw new DumpException('DUMP_BINARY_NOT_EXECUTABLE', 'binary', null, basename($configured), 'Set BACKUP_DUMP_BINARY to an executable MariaDB dump client path.');
        }

        return $resolved;
    }

    /**
     * @param  array{triggers:int,routines:int,events:int}  $objects
     * @return list<string>
     */
    public function build(string $binary, string $credentials, string $database, array $objects, bool $schemaOnly): array
    {
        $command = [
            $binary,
            '--defaults-extra-file='.$credentials,
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--no-tablespaces',
            '--hex-blob',
            '--default-character-set=utf8mb4',
            $objects['triggers'] > 0 ? '--triggers' : '--skip-triggers',
        ];
        if ($objects['routines'] > 0) {
            $command[] = '--routines';
        }
        if ($objects['events'] > 0) {
            $command[] = '--events';
        }
        if ($schemaOnly) {
            $command[] = '--no-data';
        }
        $command[] = $database;

        return $command;
    }

    /** @param array{triggers:int,routines:int,events:int} $objects */
    public function safeOptionMetadata(array $objects): array
    {
        return [
            'single_transaction' => true,
            'quick' => true,
            'skip_lock_tables' => true,
            'no_tablespaces' => true,
            'triggers' => $objects['triggers'] > 0,
            'routines' => $objects['routines'] > 0,
            'events' => $objects['events'] > 0,
        ];
    }
}
