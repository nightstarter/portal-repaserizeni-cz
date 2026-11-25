<?php

namespace App\Backup;

use RuntimeException;

final class ScheduledBackupTarget
{
    public function __construct(private readonly BackupPathGuard $paths) {}

    public function make(): string
    {
        $configured = config('backups.root');
        if (! is_string($configured) || $configured === '') {
            throw new RuntimeException('Backup root is not configured.');
        }
        $root = $this->paths->backupRoot(rtrim($configured, DIRECTORY_SEPARATOR));
        $name = 'portal-backup-'.now()->utc()->format('Ymd\THis\Z');

        return $this->paths->newBackupTarget($root.DIRECTORY_SEPARATOR.$name);
    }
}
