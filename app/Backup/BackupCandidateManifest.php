<?php

namespace App\Backup;

final class BackupCandidateManifest
{
    public function __construct(
        public readonly string $manifestPath,
        public readonly string $stagingRoot,
    ) {}
}
