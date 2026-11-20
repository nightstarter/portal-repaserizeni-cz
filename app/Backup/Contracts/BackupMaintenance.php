<?php

namespace App\Backup\Contracts;

interface BackupMaintenance
{
    /** Returns true only when this invocation enabled maintenance mode. */
    public function begin(): bool;

    public function end(bool $enabledByThisInvocation): void;
}
