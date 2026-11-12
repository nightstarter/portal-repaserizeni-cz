<?php

namespace App\Application\Orders;

use App\Enums\StockRebuildAction;
use App\Models\User;

final readonly class StockRebuildCommand
{
    public function __construct(
        public StockRebuildAction $action,
        public User $actor,
        public string $idempotencyKey,
        public array $data = [],
        public ?int $expectedLockVersion = null,
    ) {}
}
