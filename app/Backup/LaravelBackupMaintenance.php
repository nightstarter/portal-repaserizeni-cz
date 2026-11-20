<?php

namespace App\Backup;

use App\Backup\Contracts\BackupMaintenance;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

final class LaravelBackupMaintenance implements BackupMaintenance
{
    public function begin(): bool
    {
        if (! app()->environment('production') || app()->isDownForMaintenance()) {
            return false;
        }
        if (Artisan::call('down', ['--retry' => 60, '--refresh' => 15]) !== 0) {
            throw new RuntimeException('Unable to enable maintenance mode.');
        }
        $seconds = max(0, min(60, (int) config('backups.request_drain_seconds', 10)));
        if ($seconds > 0) {
            sleep($seconds);
        }

        return true;
    }

    public function end(bool $enabledByThisInvocation): void
    {
        if ($enabledByThisInvocation && Artisan::call('up') !== 0) {
            throw new RuntimeException('Unable to disable maintenance mode.');
        }
    }
}
