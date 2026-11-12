<?php

namespace App\Application\Orders;

use App\Enums\CustomRebuildAction;
use App\Enums\CustomRebuildState;
use App\Enums\OrganizationCode;
use App\Enums\UserRole;
use App\Exceptions\WorkflowConflictException;
use App\Models\Order;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class CustomRebuildActionGuard
{
    private const AK_ACTIONS = [
        CustomRebuildAction::CONFIRM_AK_RECEIPT,
        CustomRebuildAction::SEND_TO_POLAND,
        CustomRebuildAction::CONFIRM_AK_RETURN_RECEIPT,
        CustomRebuildAction::SEND_TO_CUSTOMER,
        CustomRebuildAction::MARK_READY_PICKUP,
        CustomRebuildAction::CONFIRM_CUSTOMER_PICKUP,
    ];

    private const PL_ACTIONS = [
        CustomRebuildAction::CONFIRM_PL_RECEIPT,
        CustomRebuildAction::MARK_REPAIRED_PL,
        CustomRebuildAction::SEND_TO_AUTOKLUBY,
    ];

    public function __construct(private readonly CustomRebuildStateMachine $stateMachine) {}

    public function assertActorMayPerform(User $actor, CustomRebuildAction $action): void
    {
        if (! $actor->active || ! $actor->organization->active || $actor->role === UserRole::READ_ONLY) {
            throw new AuthorizationException;
        }

        if ($action === CustomRebuildAction::ADMIN_OVERRIDE) {
            if ($actor->organization->code !== OrganizationCode::AUTOKLUBY || $actor->role !== UserRole::AK_ADMIN) {
                throw new AuthorizationException;
            }

            return;
        }

        if (in_array($action, self::AK_ACTIONS, true)) {
            $allowed = $actor->organization->code === OrganizationCode::AUTOKLUBY
                && in_array($actor->role, [UserRole::AK_ADMIN, UserRole::AK_OPERATOR], true);
        } else {
            $allowed = in_array($action, self::PL_ACTIONS, true)
                && $actor->organization->code === OrganizationCode::POLAND
                && in_array($actor->role, [UserRole::PL_MANAGER, UserRole::PL_OPERATOR], true);
        }

        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    public function nextState(Order $order, ProcessThread $thread, CustomRebuildAction $action): CustomRebuildState
    {
        $current = $thread->customRebuildState();
        $entryAction = null;

        if ($current === CustomRebuildState::RECEIVED_AK) {
            $lastAction = $thread->relationLoaded('events')
                ? $thread->events->sortByDesc('id')->first()?->action
                : $thread->events()->latest('id')->value('action');
            $entryAction = is_string($lastAction) ? CustomRebuildAction::tryFrom($lastAction) : null;
        }

        $next = $this->stateMachine->nextState($current, $action, $entryAction);

        if ($action === CustomRebuildAction::SEND_TO_CUSTOMER && $order->customer_handover !== 'GLS') {
            throw new WorkflowConflictException(__('app.workflow_errors.gls_required'));
        }

        if ($action === CustomRebuildAction::MARK_READY_PICKUP && $order->customer_handover !== 'PERSONAL_PICKUP') {
            throw new WorkflowConflictException(__('app.workflow_errors.pickup_required'));
        }

        return $next;
    }

    /** @return array<int, CustomRebuildAction> */
    public function allowedActions(Order $order, ProcessThread $thread, User $actor): array
    {
        $allowed = [];

        foreach ([...self::AK_ACTIONS, ...self::PL_ACTIONS] as $action) {
            try {
                $this->assertActorMayPerform($actor, $action);
                $this->nextState($order, $thread, $action);
                $allowed[] = $action;
            } catch (AuthorizationException|WorkflowConflictException) {
                // The same guard is used by the write path; unavailable actions stay out of the projection.
            }
        }

        return $allowed;
    }
}
