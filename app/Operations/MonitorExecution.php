<?php

namespace App\Operations;

final class MonitorExecution
{
    /** @param list<MonitorCheck> $checks */
    public function __construct(
        public readonly string $status,
        public readonly array $checks,
        public readonly ?string $runUuid,
        public readonly bool $lockContended = false,
    ) {}

    public function hasErrors(): bool
    {
        return in_array($this->status, [MonitorCheck::ERROR, MonitorCheck::CRITICAL], true);
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'run_uuid' => $this->runUuid,
            'lock_contended' => $this->lockContended,
            'checks' => array_map(fn (MonitorCheck $check): array => $check->toArray(), $this->checks),
        ];
    }
}
