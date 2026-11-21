<?php

namespace App\Backup;

use RuntimeException;

class BackupFailure extends RuntimeException
{
    /** @param array<string, scalar> $safeContext */
    public function __construct(
        public readonly string $failureCode,
        public readonly string $phase,
        public readonly ?int $processExitCode,
        public readonly string $recommendation,
        private readonly array $safeContext = [],
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
            'process_exit_code' => $this->processExitCode,
            'recommendation' => $this->recommendation,
        ] + $this->safeContext, static fn (mixed $value): bool => $value !== null);
    }

    public function withRecommendation(string $recommendation): self
    {
        return new self(
            $this->failureCode,
            $this->phase,
            $this->processExitCode,
            $recommendation,
            $this->safeContext,
        );
    }
}
