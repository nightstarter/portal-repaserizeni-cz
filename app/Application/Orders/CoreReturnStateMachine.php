<?php

namespace App\Application\Orders;

use App\Enums\CoreReturnAction;
use App\Enums\CoreReturnState;
use App\Exceptions\WorkflowConflictException;

final class CoreReturnStateMachine
{
    public function nextState(CoreReturnState $current, CoreReturnAction $action): CoreReturnState
    {
        $next = match ([$current, $action]) {
            [CoreReturnState::WAITING_CORE, CoreReturnAction::CONFIRM_CORE_RECEIPT_AK] => CoreReturnState::CORE_RECEIVED_AK,
            [CoreReturnState::CORE_RECEIVED_AK, CoreReturnAction::SEND_TO_POLAND] => CoreReturnState::SENT_TO_PL,
            [CoreReturnState::SENT_TO_PL, CoreReturnAction::CONFIRM_CORE_RECEIPT_PL] => CoreReturnState::CORE_INSPECTION,
            [CoreReturnState::CORE_INSPECTION, CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN] => CoreReturnState::DEPOSIT_FULL_APPROVED,
            [CoreReturnState::CORE_INSPECTION, CoreReturnAction::APPROVE_REDUCED_DEPOSIT_RETURN] => CoreReturnState::DEPOSIT_REDUCED_APPROVED,
            [CoreReturnState::CORE_INSPECTION, CoreReturnAction::CONFIRM_NO_SUPPLIER_DEPOSIT] => CoreReturnState::CORE_SETTLED_NO_DEPOSIT,
            [CoreReturnState::DEPOSIT_FULL_APPROVED, CoreReturnAction::ISSUE_SUPPLIER_CREDIT_NOTE],
            [CoreReturnState::DEPOSIT_REDUCED_APPROVED, CoreReturnAction::ISSUE_SUPPLIER_CREDIT_NOTE] => CoreReturnState::CREDIT_ISSUED,
            [CoreReturnState::CREDIT_ISSUED, CoreReturnAction::CONFIRM_CREDIT_PAID] => CoreReturnState::CREDIT_PAID,
            default => null,
        };

        if ($next === null) {
            throw new WorkflowConflictException(__('app.workflow_errors.invalid_transition', [
                'action' => $action->value,
                'state' => $current->value,
            ]));
        }

        return $next;
    }
}
