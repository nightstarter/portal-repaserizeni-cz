<?php

namespace App\Policies;

use App\Enums\OrderType;
use App\Enums\OrganizationCode;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->active && $user->organization->active;
    }

    public function view(User $user, Order $order): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->organization->code === OrganizationCode::AUTOKLUBY) {
            return true;
        }

        if ($order->order_type === OrderType::CUSTOM_REBUILD) {
            return Order::query()
                ->customRebuildVisibleTo($user)
                ->whereKey($order->id)
                ->exists();
        }

        return $order->processThreads()->visibleTo($user)->exists();
    }
}
