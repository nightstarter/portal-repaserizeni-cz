<?php

namespace App\Application\Orders;

use App\Enums\CoreReturnAction;
use App\Models\User;

final readonly class CoreReturnCommand
{
    public function __construct(
        public CoreReturnAction $action,
        public User $actor,
        public string $idempotencyKey,
        public array $data = [],
        public ?int $expectedLockVersion = null,
    ) {}
}
