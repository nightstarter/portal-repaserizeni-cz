<?php

namespace App\Application\Messages;

use App\Models\User;

final readonly class PostMessageCommand
{
    public function __construct(
        public User $actor,
        public string $body,
        public string $idempotencyKey,
        public array $attachments = [],
    ) {}
}
