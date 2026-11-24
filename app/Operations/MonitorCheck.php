<?php

namespace App\Operations;

final class MonitorCheck
{
    public const OK = 'OK';

    public const INFO = 'INFO';

    public const WARN = 'WARN';

    public const ERROR = 'ERROR';

    public const CRITICAL = 'CRITICAL';

    public function __construct(
        public readonly string $key,
        public readonly string $severity,
        public readonly string $summary,
        public readonly array $metadata = [],
        public readonly bool $alertable = true,
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->key, $this->severity, $this->metadata], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'severity' => $this->severity,
            'summary' => $this->summary,
            'metadata' => $this->metadata,
            'fingerprint' => $this->fingerprint(),
            'alertable' => $this->alertable,
        ];
    }
}
