<?php

namespace App\Backup\Contracts;

interface DatabaseDumpRunner
{
    public function dump(string $outputPath, string $credentialsDirectory): void;
}
