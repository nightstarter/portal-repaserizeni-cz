<?php

namespace App\Backup\Contracts;

interface DatabaseDumpRunner
{
    /** @return array<string, mixed> Sanitized manifest metadata. */
    public function dump(string $outputPath, string $credentialsDirectory): array;
}
