<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;

class MessagePolicy
{
    public function create(User $user, Order $order): bool
    {
        return $user->role !== UserRole::READ_ONLY && $user->can('view', $order);
    }

    public function view(User $user, Message $message): bool
    {
        return $user->can('view', $message->order);
    }

    public function update(User $user, Message $message): bool
    {
        return false;
    }

    public function delete(User $user, Message $message): bool
    {
        return false;
    }
}
