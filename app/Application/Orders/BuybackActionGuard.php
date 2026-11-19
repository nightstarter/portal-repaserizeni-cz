<?php

namespace App\Application\Orders;

use App\Enums\BuybackAction;
use App\Enums\OrganizationCode;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class BuybackActionGuard
{
    private const AK_ACTIONS = [
        BuybackAction::CREATE, BuybackAction::SET_INTERNAL_PRICE, BuybackAction::REQUEST_PL_OFFER,
        BuybackAction::REQUEST_FINAL_PRICE, BuybackAction::RECORD_CUSTOMER_ACCEPTANCE,
        BuybackAction::RECEIVE_ITEM_AK, BuybackAction::CHECK_ITEM_AK, BuybackAction::RETAIN_AK,
        BuybackAction::DECIDE_PL_BUY, BuybackAction::SEND_TO_PL, BuybackAction::RECORD_INVOICE_TO_PL,
    ];

    private const APPROVAL_ACTIONS = [
        BuybackAction::APPROVE_FINAL_PRICE, BuybackAction::CONFIRM_CUSTOMER_OFFER,
        BuybackAction::QUEUE_CUSTOMER_OFFER,
    ];

    public function assertMayPerform(User $actor, BuybackAction $action, ?ProcessThread $thread = null): void
    {
        if (! $actor->active || ! $actor->organization->active || $actor->role === UserRole::READ_ONLY) {
            throw new AuthorizationException;
        }
        if (in_array($action, self::APPROVAL_ACTIONS, true)) {
            if (! $actor->hasPermission(Permission::BUYBACK_FINAL_PRICE_APPROVE)) {
                throw new AuthorizationException;
            }

            return;
        }
        if ($action === BuybackAction::MARK_PAID_BY_PL) {
            if (! $actor->hasPermission(Permission::BUYBACK_PAYMENT_CONFIRM)) {
                throw new AuthorizationException;
            }

            return;
        }
        if ($action === BuybackAction::ADMIN_OVERRIDE) {
            if ($actor->organization->code !== OrganizationCode::AUTOKLUBY || $actor->role !== UserRole::AK_ADMIN) {
                throw new AuthorizationException;
            }

            return;
        }
        if (in_array($action, [BuybackAction::SUBMIT_PL_OFFER, BuybackAction::CONFIRM_PL_RECEIPT, BuybackAction::REQUIRE_INVOICE_TO_PL], true)) {
            if ($actor->organization->code !== OrganizationCode::POLAND
                || ! in_array($actor->role, [UserRole::PL_MANAGER, UserRole::PL_OPERATOR], true)
                || $thread === null
                || ! ProcessThread::query()->visibleTo($actor)->whereKey($thread->id)->exists()) {
                throw new AuthorizationException;
            }

            return;
        }
        if (! in_array($action, self::AK_ACTIONS, true)
            || $actor->organization->code !== OrganizationCode::AUTOKLUBY
            || ! in_array($actor->role, [UserRole::AK_ADMIN, UserRole::AK_OPERATOR], true)) {
            throw new AuthorizationException;
        }
    }
}
