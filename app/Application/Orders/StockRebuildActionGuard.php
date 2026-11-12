<?php

namespace App\Application\Orders;

use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\StockRebuildAction;
use App\Enums\StockRebuildState;
use App\Enums\UserRole;
use App\Exceptions\WorkflowConflictException;
use App\Models\Order;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class StockRebuildActionGuard
{
    private const PL_ACTIONS = [
        StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE,
        StockRebuildAction::SET_PL_STOCK_ETA,
        StockRebuildAction::SEND_TO_AUTOKLUBY,
    ];

    private const AK_ACTIONS = [
        StockRebuildAction::CONFIRM_AK_STOCK_RECEIPT,
        StockRebuildAction::SEND_TO_CUSTOMER,
        StockRebuildAction::MARK_READY_PICKUP,
        StockRebuildAction::CONFIRM_CUSTOMER_PICKUP,
    ];

    public function __construct(private readonly StockRebuildStateMachine $stateMachine) {}

    public function assertActorMayPerform(User $actor, StockRebuildAction $action): void
    {
        if (! $actor->active || ! $actor->organization->active || $actor->role === UserRole::READ_ONLY) {
            throw new AuthorizationException;
        }

        if ($action === StockRebuildAction::ADMIN_OVERRIDE) {
            if ($actor->organization->code !== OrganizationCode::AUTOKLUBY || $actor->role !== UserRole::AK_ADMIN) {
                throw new AuthorizationException;
            }

            return;
        }

        $allowed = in_array($action, self::PL_ACTIONS, true)
            ? $actor->organization->code === OrganizationCode::POLAND
                && in_array($actor->role, [UserRole::PL_MANAGER, UserRole::PL_OPERATOR], true)
            : in_array($action, self::AK_ACTIONS, true)
                && $actor->organization->code === OrganizationCode::AUTOKLUBY
                && in_array($actor->role, [UserRole::AK_ADMIN, UserRole::AK_OPERATOR], true);

        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    public function nextState(Order $order, ProcessThread $thread, StockRebuildAction $action): StockRebuildState
    {
        $next = $this->stateMachine->nextState(
            $thread->type,
            StockRebuildState::from($thread->state),
            $action,
        );

        if ($action === StockRebuildAction::SEND_TO_CUSTOMER && $order->customer_handover !== 'GLS') {
            throw new WorkflowConflictException(__('app.workflow_errors.gls_required'));
        }

        if ($action === StockRebuildAction::MARK_READY_PICKUP && $order->customer_handover !== 'PERSONAL_PICKUP') {
            throw new WorkflowConflictException(__('app.workflow_errors.pickup_required'));
        }

        return $next;
    }

    public function assertThreadType(ProcessThreadType $type): void
    {
        if (! in_array($type, [ProcessThreadType::STOCK_REQUEST, ProcessThreadType::STOCK_REPLENISHMENT], true)) {
            throw new WorkflowConflictException(__('app.workflow_errors.invalid_stock_thread'));
        }
    }
}
