<?php

namespace App\Console\Commands;

use App\Backup\BackupCoordinator;
use App\Backup\BackupFailure;
use App\Backup\LatestBackupManifest;
use App\Backup\ScheduledBackupTarget;
use App\Models\ScheduledBackupRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class BackupScheduled extends Command
{
    protected $signature = 'backup:scheduled {--json : Emit machine-readable JSON}';

    protected $description = 'Create a scheduled verified backup and atomically advance its latest pointer';

    public function handle(
        BackupCoordinator $coordinator,
        ScheduledBackupTarget $targets,
        LatestBackupManifest $latest,
    ): int {
        $started = now();
        if (! (bool) config('backups.scheduled_enabled', false)) {
            return $this->finish(ScheduledBackupRun::SKIPPED_DISABLED, $started, null, null, null, self::SUCCESS);
        }

        try {
            $target = $targets->make();
        } catch (Throwable) {
            return $this->finish(ScheduledBackupRun::FAILED, $started, null, null, 'SCHEDULE_CONFIGURATION_INVALID', self::FAILURE);
        }

        try {
            $result = $coordinator->create($target);
        } catch (BackupFailure $failure) {
            if ($failure->failureCode === 'BACKUP_LOCKED') {
                return $this->finish(ScheduledBackupRun::SKIPPED_LOCKED, $started, basename($target), null, 'BACKUP_LOCKED', self::SUCCESS);
            }

            return $this->finish(ScheduledBackupRun::FAILED, $started, basename($target), null, $failure->failureCode, self::FAILURE);
        } catch (Throwable) {
            return $this->finish(ScheduledBackupRun::FAILED, $started, basename($target), null, 'BACKUP_FAILED_UNKNOWN', self::FAILURE);
        }

        try {
            $latest->publish($target);
        } catch (Throwable) {
            return $this->finish(ScheduledBackupRun::FAILED, $started, basename($target), (string) ($result['backup_id'] ?? ''), 'POINTER_UPDATE_FAILED', self::FAILURE);
        }

        return $this->finish(ScheduledBackupRun::SUCCESS, $started, basename($target), (string) $result['backup_id'], null, self::SUCCESS);
    }

    private function finish(
        string $status,
        mixed $started,
        ?string $targetName,
        ?string $backupId,
        ?string $failureCode,
        int $exitCode,
    ): int {
        $completed = now();
        ScheduledBackupRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'status' => $status,
            'target_name' => $targetName,
            'backup_id' => $backupId !== '' ? $backupId : null,
            'failure_code' => $failureCode,
            'started_at' => $started,
            'completed_at' => $completed,
        ]);
        $payload = array_filter([
            'status' => $status,
            'backup_id' => $backupId !== '' ? $backupId : null,
            'target_name' => $targetName,
            'failure_code' => $failureCode,
            'completed_at_utc' => $completed->utc()->format('Y-m-d\TH:i:s\Z'),
        ], static fn (mixed $value): bool => $value !== null);
        Log::info('scheduled_backup_finished', $payload);
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Scheduled backup: '.$status.($failureCode !== null ? ' ('.$failureCode.')' : '.'));
        }

        return $exitCode;
    }
}
