<?php

namespace App\Operations;

final class PreflightCheck
{
    public const OK = 'OK';

    public const WARN = 'WARN';

    public const ERROR = 'ERROR';

    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $message,
        public readonly array $context = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
