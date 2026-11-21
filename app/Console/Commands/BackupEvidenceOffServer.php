<?php

namespace App\Console\Commands;

use App\Backup\RecoveryEvidenceStore;
use Illuminate\Console\Command;
use Throwable;

final class BackupEvidenceOffServer extends Command
{
    protected $signature = 'backup:evidence:off-server
        {path : Absolute backup directory or manifest path}
        {--evidence-id= : Non-secret incident or change identifier}
        {--confirmed : Explicitly attest that the complete off-server copy was independently verified}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Record an operator attestation for an independently verified off-server backup copy';

    public function handle(RecoveryEvidenceStore $evidence): int
    {
        $evidenceId = $this->option('evidence-id');
        if (! is_string($evidenceId) || $evidenceId === '' || ! $this->option('confirmed')) {
            return $this->failure('An explicit --evidence-id and --confirmed attestation are required.');
        }
        try {
            $result = $evidence->confirmOffServer((string) $this->argument('path'), $evidenceId);
        } catch (Throwable) {
            return $this->failure('Off-server evidence was not recorded; no sensitive detail was emitted.');
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('OK: operator off-server verification attestation recorded.');
            $this->line('Backup ID: '.$result['backup_id']);
            $this->line('Evidence ID: '.$result['evidence_id']);
            $this->line('Idempotent replay: '.($result['idempotent'] ? 'yes' : 'no'));
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
