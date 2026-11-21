<?php

namespace App\Console\Commands;

use App\Backup\RestoreCheckService;
use Illuminate\Console\Command;
use Throwable;

final class BackupRestoreCheck extends Command
{
    protected $signature = 'backup:restore-check
        {path : Absolute backup directory or manifest path}
        {--target= : New empty private-storage restore target outside production storage}
        {--evidence-id= : Non-secret incident or change identifier for append-only restore evidence}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Validate an isolated restored database and restore private files into a safe empty target';

    public function handle(RestoreCheckService $service): int
    {
        $target = $this->option('target');
        $evidenceId = $this->option('evidence-id');
        if (! is_string($target) || $target === '' || ! is_string($evidenceId) || $evidenceId === '') {
            return $this->failure('An explicit absolute --target and non-secret --evidence-id are required.');
        }
        try {
            $result = $service->run((string) $this->argument('path'), $target, $evidenceId);
        } catch (Throwable) {
            return $this->failure('Restore check failed; no sensitive detail was emitted.');
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('OK: isolated restore check passed.');
            $this->line('Backup ID: '.$result['backup_id']);
            $this->line('Private files checked: '.$result['copied_private_files']);
        }

        return self::SUCCESS;
    }

    private function failure(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['status' => 'ERROR', 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
