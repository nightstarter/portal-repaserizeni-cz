<?php

namespace App\Application\Orders;

use App\Enums\CustomRebuildAction;
use App\Models\User;

final readonly class CustomRebuildCommand
{
    public function __construct(
        public CustomRebuildAction $action,
        public User $actor,
        public string $idempotencyKey,
        public array $data = [],
        public ?int $expectedLockVersion = null,
    ) {}
}
