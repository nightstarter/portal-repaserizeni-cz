<?php

namespace App\Backup;

final class DumpException extends BackupFailure
{
    public function __construct(
        string $failureCode,
        string $phase,
        ?int $dumpExitCode,
        string $binary,
        string $recommendation,
    ) {
        parent::__construct($failureCode, $phase, $dumpExitCode, $recommendation, ['binary' => $binary]);
    }
}
