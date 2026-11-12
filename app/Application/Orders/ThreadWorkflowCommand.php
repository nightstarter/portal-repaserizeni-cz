<?php

namespace App\Application\Orders;

use App\Models\User;

final readonly class ThreadWorkflowCommand
{
    public function __construct(
        public string $action,
        public User $actor,
        public string $idempotencyKey,
        public array $data = [],
        public ?int $expectedLockVersion = null,
    ) {}
}
