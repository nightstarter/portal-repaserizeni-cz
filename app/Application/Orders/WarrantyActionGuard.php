<?php

namespace App\Application\Orders;

use App\Enums\OrganizationCode;
use App\Enums\UserRole;
use App\Enums\WarrantyAction;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class WarrantyActionGuard
{
    private const AK_ACTIONS = [
        WarrantyAction::CREATE_CYCLE,
        WarrantyAction::PREPARE_TO_POLAND,
        WarrantyAction::SEND_TO_POLAND,
        WarrantyAction::CONFIRM_AUTOKLUBY_RECEIPT,
        WarrantyAction::SEND_TO_CUSTOMER,
        WarrantyAction::CONFIRM_CUSTOMER_PICKUP,
    ];

    private const PL_ACTIONS = [
        WarrantyAction::CONFIRM_POLAND_RECEIPT,
        WarrantyAction::COMPLETE_REPAIR,
        WarrantyAction::PREPARE_TO_AUTOKLUBY,
        WarrantyAction::SEND_TO_AUTOKLUBY,
    ];

    public function assertMayPerform(User $actor, WarrantyAction $action, ?ProcessThread $thread = null): void
    {
        $this->assertRoleMayPerform($actor, $action);

        if ($actor->organization->code === OrganizationCode::POLAND
            && ($thread === null || ! ProcessThread::query()->visibleTo($actor)->whereKey($thread->id)->exists())) {
            throw new AuthorizationException;
        }
    }

    public function assertRoleMayPerform(User $actor, WarrantyAction $action): void
    {
        if (! $actor->active || ! $actor->organization->active || $actor->role === UserRole::READ_ONLY) {
            throw new AuthorizationException;
        }

        if ($action === WarrantyAction::ADMIN_OVERRIDE) {
            if ($actor->organization->code !== OrganizationCode::AUTOKLUBY || $actor->role !== UserRole::AK_ADMIN) {
                throw new AuthorizationException;
            }

            return;
        }

        $allowed = in_array($action, self::AK_ACTIONS, true)
            ? $actor->organization->code === OrganizationCode::AUTOKLUBY
                && in_array($actor->role, [UserRole::AK_ADMIN, UserRole::AK_OPERATOR], true)
            : in_array($action, self::PL_ACTIONS, true)
                && $actor->organization->code === OrganizationCode::POLAND
                && in_array($actor->role, [UserRole::PL_MANAGER, UserRole::PL_OPERATOR], true);

        if (! $allowed) {
            throw new AuthorizationException;
        }

    }
}
