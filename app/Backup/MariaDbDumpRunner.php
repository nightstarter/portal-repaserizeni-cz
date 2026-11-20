<?php

namespace App\Backup;

use App\Backup\Contracts\DatabaseDumpRunner;
use App\Backup\Contracts\DumpProcessRunner;
use Throwable;

final class MariaDbDumpRunner implements DatabaseDumpRunner
{
    public function __construct(
        private readonly DumpProcessRunner $processes,
        private readonly MariaDbClientConfig $credentials,
        private readonly MariaDbDumpCommand $commands,
        private readonly DatabaseObjectInspector $objects,
        private readonly DumpFailureClassifier $failures,
    ) {}

    public function dump(string $outputPath, string $credentialsDirectory): array
    {
        $database = $this->credentials->databaseConfiguration();
        $binary = $this->commands->binary();
        $inventory = $this->objects->inspect();
        $credentials = null;
        try {
            $credentials = $this->credentials->create($credentialsDirectory, $database);
            $command = $this->commands->build($binary, $credentials, (string) $database['database'], $inventory, false);
            $result = $this->processes->run($command, $outputPath, max(60, (int) config('backups.dump_timeout_seconds', 3600)));
            if ($result->exitCode !== 0) {
                throw $this->failures->fromResult($result, $binary, $inventory);
            }
            if (! is_file($outputPath) || filesize($outputPath) < 1 || ! chmod($outputPath, 0600)) {
                throw new DumpException('DUMP_OUTPUT_WRITE_FAILED', 'dump_output', $result->exitCode, basename($binary), 'Use a new writable backup target on a filesystem with sufficient free space.');
            }

            return [
                'logical_objects' => $inventory,
                'options' => $this->commands->safeOptionMetadata($inventory),
            ];
        } catch (Throwable $exception) {
            @unlink($outputPath);
            if ($exception instanceof DumpException) {
                throw $exception;
            }
            throw new DumpException('DUMP_FAILED_UNKNOWN', 'dump', null, basename($binary), 'Run backup:diagnose --json and review only its sanitized capability results.');
        } finally {
            if (is_string($credentials)) {
                @unlink($credentials);
            }
        }
    }
}
