<?php

namespace App\Application\Orders;

use App\Enums\WarrantyAction;
use App\Models\User;

final readonly class WarrantyCommand
{
    public function __construct(
        public WarrantyAction $action,
        public User $actor,
        public string $idempotencyKey,
        public array $data = [],
        public ?int $expectedLockVersion = null,
    ) {}
}
