<?php

namespace App\Policies;

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

        return $order->processThreads()
            ->where('responsible_organization_id', $user->organization_id)
            ->exists()
            || $order->threadEvents()
                ->where(function ($history) use ($user): void {
                    $history
                        ->where('responsible_organization_id', $user->organization_id)
                        ->orWhere('actor_organization_id', $user->organization_id);
                })
                ->exists();
    }
}
