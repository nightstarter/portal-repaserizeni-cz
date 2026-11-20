<?php

namespace App\Console\Commands;

use App\Backup\BackupCreator;
use App\Backup\DumpException;
use Illuminate\Console\Command;
use Throwable;

final class BackupCreate extends Command
{
    protected $signature = 'backup:create {--target= : New absolute final backup directory} {--json : Emit machine-readable JSON}';

    protected $description = 'Create and verify a consistent MariaDB and private-storage backup package';

    public function handle(BackupCreator $creator): int
    {
        $target = $this->option('target');
        if (! is_string($target) || $target === '') {
            return $this->failure('An explicit absolute --target is required.');
        }
        try {
            $result = $creator->create($target);
        } catch (DumpException $exception) {
            return $this->failureDetails($exception->safeDetails());
        } catch (Throwable) {
            return $this->failure('Backup creation failed; database-tool and secret details were suppressed.');
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

    private function failure(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['status' => 'ERROR', 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    /** @param array<string, mixed> $details */
    private function failureDetails(array $details): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error('Backup creation failed: '.$details['code']);
            $this->line('Phase: '.$details['phase']);
            $this->line('Binary: '.$details['binary']);
            if (isset($details['dump_exit_code'])) {
                $this->line('Dump exit code: '.$details['dump_exit_code']);
            }
            $this->line('Recommendation: '.$details['recommendation']);
        }

        return self::FAILURE;
    }
}
