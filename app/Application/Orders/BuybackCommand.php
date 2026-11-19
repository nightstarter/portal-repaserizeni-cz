<?php

namespace App\Application\Orders;

use App\Enums\BuybackAction;
use App\Models\User;

final readonly class BuybackCommand
{
    public function __construct(
        public BuybackAction $action,
        public User $actor,
        public string $idempotencyKey,
        public array $data = [],
        public ?int $expectedLockVersion = null,
    ) {}
}
