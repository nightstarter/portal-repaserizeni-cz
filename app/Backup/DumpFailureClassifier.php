<?php

namespace App\Backup;

final class DumpFailureClassifier
{
    /** @param array{triggers:int,routines:int,events:int} $objects */
    public function fromResult(DumpProcessResult $result, string $binary, array $objects): DumpException
    {
        $error = strtolower($result->stderr);
        [$code, $recommendation] = match (true) {
            str_contains($error, 'unknown option'), str_contains($error, 'unknown variable') => [
                'DUMP_UNSUPPORTED_OPTION', 'Verify that BACKUP_DUMP_BINARY points to a MariaDB 10.11 compatible dump client.',
            ],
            str_contains($error, 'access denied for user') && ! str_contains($error, 'you need') => [
                'DUMP_AUTHENTICATION_FAILED', 'Verify the separately managed database credentials without printing or passing them on the command line.',
            ],
            str_contains($error, "can't connect"), str_contains($error, 'cannot connect'), str_contains($error, 'connection refused'),
            str_contains($error, 'unknown server host'), str_contains($error, 'lost connection') => [
                'DUMP_CONNECTION_FAILED', 'Verify database reachability and whether the application uses TCP or a Unix socket.',
            ],
            str_contains($error, 'permission denied'), str_contains($error, 'access denied; you need'),
            str_contains($error, 'you need (at least one of)'),
            str_contains($error, 'command denied'), str_contains($error, 'insufficient privilege') => [
                'DUMP_PERMISSION_DENIED', $this->permissionRecommendation($objects),
            ],
            str_contains($error, 'no space left'), str_contains($error, 'cannot create'),
            str_contains($error, "can't create"), str_contains($error, 'error writing file') => [
                'DUMP_OUTPUT_WRITE_FAILED', 'Use a new writable backup target on a filesystem with sufficient free space.',
            ],
            default => ['DUMP_FAILED_UNKNOWN', 'Run backup:diagnose --json and review only its sanitized capability results.'],
        };

        return new DumpException($code, 'database_dump', $result->exitCode, basename($binary), $recommendation);
    }

    /** @param array{triggers:int,routines:int,events:int} $objects */
    private function permissionRecommendation(array $objects): string
    {
        if (array_sum($objects) > 0) {
            return 'The database contains logical objects; grant only the minimum SHOW VIEW/TRIGGER/EVENT and routine metadata privileges approved by the DBA, then rerun diagnose.';
        }

        return 'Verify minimum read-only dump privileges for tables and views; no privileges are changed automatically.';
    }
}
