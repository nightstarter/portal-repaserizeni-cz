<?php

namespace App\Application\Shipments;

use App\Models\User;

final readonly class ShipmentCommand
{
    public function __construct(
        public User $actor,
        public string $idempotencyKey,
        public array $data = [],
        public ?int $expectedLockVersion = null,
    ) {}
}
