<?php

namespace App\Application\Orders;

use App\Models\User;

final readonly class CreateBuybackCommand
{
    public function __construct(
        public User $actor,
        public string $idempotencyKey,
        public array $data,
    ) {}
}
