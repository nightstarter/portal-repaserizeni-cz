<?php

namespace App\Backup\Contracts;

use App\Backup\DumpProcessResult;

interface DumpProcessRunner
{
    /**
     * @param  list<string>  $command
     */
    public function run(array $command, ?string $stdoutPath, int $timeoutSeconds): DumpProcessResult;
}
