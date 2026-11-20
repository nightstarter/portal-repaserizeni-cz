<?php

namespace App\Backup;

use RuntimeException;

final class DumpException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly string $phase,
        public readonly ?int $dumpExitCode,
        public readonly string $binary,
        public readonly string $recommendation,
    ) {
        parent::__construct($failureCode);
    }

    /** @return array<string, mixed> */
    public function safeDetails(): array
    {
        return array_filter([
            'status' => 'ERROR',
            'code' => $this->failureCode,
            'phase' => $this->phase,
            'dump_exit_code' => $this->dumpExitCode,
            'binary' => $this->binary,
            'recommendation' => $this->recommendation,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
