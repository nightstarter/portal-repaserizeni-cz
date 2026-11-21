<?php

namespace App\Console\Commands;

use App\Backup\BackupManifestVerifier;
use App\Backup\RecoveryEvidenceStore;
use Illuminate\Console\Command;

final class BackupVerify extends Command
{
    protected $signature = 'backup:verify {path : Absolute backup directory or manifest path} {--json : Emit machine-readable JSON}';

    protected $description = 'Verify a backup manifest, every declared size and SHA-256 checksum';

    public function handle(BackupManifestVerifier $verifier, RecoveryEvidenceStore $evidence): int
    {
        $result = $verifier->verify((string) $this->argument('path'));
        $evidenceResult = $evidence->inspect($result);
        $evidenceStatus = match ($evidenceResult['status']) {
            'VALID' => 'OK',
            default => 'WARN',
        };
        if ($this->option('json')) {
            $this->line((string) json_encode($result->toArray() + [
                'recovery_evidence' => ['status' => $evidenceStatus, 'state' => $evidenceResult['status']],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line(($result->valid ? 'OK' : 'ERROR').': '.($result->valid ? 'Manifest and all file checksums are valid.' : 'Backup verification failed.'));
            foreach ($result->warnings as $warning) {
                $this->warn('WARN: '.$warning);
            }
            $this->line($evidenceStatus.': recovery evidence sidecar is '.$evidenceResult['status'].'.');
        }

        return $result->valid ? self::SUCCESS : self::FAILURE;
    }
}
