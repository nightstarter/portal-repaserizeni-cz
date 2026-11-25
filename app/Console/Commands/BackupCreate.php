<?php

namespace App\Console\Commands;

use App\Backup\BackupCoordinator;
use App\Backup\BackupFailure;
use Illuminate\Console\Command;
use Throwable;

final class BackupCreate extends Command
{
    protected $signature = 'backup:create {--target= : New absolute final backup directory} {--json : Emit machine-readable JSON}';

    protected $description = 'Create and verify a consistent MariaDB and private-storage backup package';

    public function handle(BackupCoordinator $coordinator): int
    {
        $target = $this->option('target');
        if (! is_string($target) || $target === '') {
            return $this->failureDetails((new BackupFailure(
                'FINALIZE_FAILED',
                'FINALIZE',
                null,
                'Provide a new absolute --target outside the repository, public and application storage.',
            ))->safeDetails());
        }
        try {
            $result = $coordinator->create($target);
        } catch (BackupFailure $exception) {
            return $this->failureDetails($exception->safeDetails());
        } catch (Throwable) {
            return $this->failureDetails((new BackupFailure(
                'BACKUP_FAILED_UNKNOWN',
                'UNKNOWN',
                null,
                'Stop further attempts and inspect application logs through the restricted operator channel.',
            ))->safeDetails());
        }
        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('COMPLETE: backup package was created and verified.');
            $this->line('Backup ID: '.$result['backup_id']);
            $this->line('Target: '.$result['target']);
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $details */
    private function failureDetails(array $details): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error('Backup creation failed: '.$details['code']);
            $this->line('Phase: '.$details['phase']);
            if (isset($details['process_exit_code'])) {
                $this->line('Process exit code: '.$details['process_exit_code']);
            }
            $this->line('Recommendation: '.$details['recommendation']);
        }

        return self::FAILURE;
    }
}
