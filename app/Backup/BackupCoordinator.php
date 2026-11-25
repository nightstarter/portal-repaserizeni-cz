<?php

namespace App\Backup;

use Illuminate\Support\Facades\Cache;

class BackupCoordinator
{
    public const LOCK_NAME = 'backup-creation-global';

    public function __construct(private readonly BackupCreator $creator) {}

    /** @return array<string,mixed> */
    public function create(string $target): array
    {
        $seconds = max(60, (int) config('backups.schedule_lock_minutes', 120) * 60);
        $lock = Cache::lock(self::LOCK_NAME, $seconds);
        if (! $lock->get()) {
            throw new BackupFailure(
                'BACKUP_LOCKED',
                'LOCK',
                null,
                'Another manual or scheduled backup owns the shared backup lock; retry only after that run finishes or the lock expires.',
            );
        }

        try {
            return $this->creator->create($target);
        } finally {
            $lock->release();
        }
    }
}
