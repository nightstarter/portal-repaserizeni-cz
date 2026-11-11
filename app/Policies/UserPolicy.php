<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdministrator();
    }

    public function view(User $actor, User $subject): bool
    {
        return $actor->isAdministrator() || $actor->is($subject);
    }

    public function update(User $actor, User $subject): bool
    {
        return $actor->isAdministrator() || $actor->is($subject);
    }

    public function manage(User $actor): bool
    {
        return $actor->isAdministrator();
    }
}
