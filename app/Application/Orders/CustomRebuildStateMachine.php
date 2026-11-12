<?php

namespace App\Application\Orders;

use App\Enums\CustomRebuildAction;
use App\Enums\CustomRebuildState;
use App\Exceptions\WorkflowConflictException;
use Illuminate\Container\Container;

final class CustomRebuildStateMachine
{
    public function nextState(
        CustomRebuildState $current,
        CustomRebuildAction $action,
        ?CustomRebuildAction $entryAction = null,
    ): CustomRebuildState {
        $next = match ([$current, $action]) {
            [CustomRebuildState::WAITING_CUSTOMER_ITEM, CustomRebuildAction::CONFIRM_AK_RECEIPT] => CustomRebuildState::RECEIVED_AK,
            [CustomRebuildState::RECEIVED_AK, CustomRebuildAction::SEND_TO_POLAND] => $entryAction === CustomRebuildAction::CONFIRM_AK_RECEIPT ? CustomRebuildState::SENT_TO_PL : null,
            [CustomRebuildState::SENT_TO_PL, CustomRebuildAction::CONFIRM_PL_RECEIPT] => CustomRebuildState::RECEIVED_PL_REPAIR,
            [CustomRebuildState::RECEIVED_PL_REPAIR, CustomRebuildAction::MARK_REPAIRED_PL] => CustomRebuildState::REPAIRED_PL,
            [CustomRebuildState::REPAIRED_PL, CustomRebuildAction::SEND_TO_AUTOKLUBY] => CustomRebuildState::SENT_TO_AK,
            [CustomRebuildState::SENT_TO_AK, CustomRebuildAction::CONFIRM_AK_RETURN_RECEIPT] => CustomRebuildState::RECEIVED_AK,
            [CustomRebuildState::RECEIVED_AK, CustomRebuildAction::SEND_TO_CUSTOMER] => $entryAction === CustomRebuildAction::CONFIRM_AK_RETURN_RECEIPT ? CustomRebuildState::SENT_CUSTOMER : null,
            [CustomRebuildState::RECEIVED_AK, CustomRebuildAction::MARK_READY_PICKUP] => $entryAction === CustomRebuildAction::CONFIRM_AK_RETURN_RECEIPT ? CustomRebuildState::READY_PICKUP : null,
            [CustomRebuildState::READY_PICKUP, CustomRebuildAction::CONFIRM_CUSTOMER_PICKUP] => CustomRebuildState::PICKED_UP,
            default => null,
        };

        if ($next === null) {
            $parameters = ['action' => $action->value, 'state' => $current->value];
            $message = Container::getInstance()->bound('translator')
                ? __('app.workflow_errors.invalid_transition', $parameters)
                : "Action {$action->value} is not allowed from state {$current->value}.";

            throw new WorkflowConflictException($message);
        }

        return $next;
    }
}
