<?php

namespace App\Domain\Messages;

use App\Models\Message;

final readonly class MessagePosted
{
    public const TYPE = 'discussion.message-posted.v1';

    public function __construct(public Message $message) {}

    public function payload(): array
    {
        return [
            'message_id' => $this->message->id,
            'message_token' => $this->message->public_token,
            'order_id' => $this->message->order_id,
            'author_user_id' => $this->message->author_user_id,
            'author_organization_id' => $this->message->author_organization_id,
            'created_at' => $this->message->created_at->toIso8601String(),
        ];
    }
}
