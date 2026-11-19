<?php

namespace App\Application\Orders;

use App\Enums\WarrantyAction;
use App\Enums\WarrantyState;
use App\Exceptions\WorkflowConflictException;

final class WarrantyStateMachine
{
    public function nextState(WarrantyState $state, WarrantyAction $action): WarrantyState
    {
        $next = match ([$state, $action]) {
            [WarrantyState::RECEIVED_AK, WarrantyAction::PREPARE_TO_POLAND] => WarrantyState::READY_TO_PL,
            [WarrantyState::READY_TO_PL, WarrantyAction::SEND_TO_POLAND] => WarrantyState::SENT_TO_PL,
            [WarrantyState::SENT_TO_PL, WarrantyAction::CONFIRM_POLAND_RECEIPT] => WarrantyState::RECEIVED_PL,
            [WarrantyState::RECEIVED_PL, WarrantyAction::COMPLETE_REPAIR] => WarrantyState::REPAIR_COMPLETED,
            [WarrantyState::REPAIR_COMPLETED, WarrantyAction::PREPARE_TO_AUTOKLUBY] => WarrantyState::READY_TO_AK,
            [WarrantyState::READY_TO_AK, WarrantyAction::SEND_TO_AUTOKLUBY] => WarrantyState::SENT_TO_AK,
            [WarrantyState::SENT_TO_AK, WarrantyAction::CONFIRM_AUTOKLUBY_RECEIPT] => WarrantyState::RECEIVED_AK_BACK,
            [WarrantyState::RECEIVED_AK_BACK, WarrantyAction::SEND_TO_CUSTOMER] => WarrantyState::SENT_TO_CUSTOMER,
            [WarrantyState::RECEIVED_AK_BACK, WarrantyAction::CONFIRM_CUSTOMER_PICKUP] => WarrantyState::PICKED_UP_BY_CUSTOMER,
            default => null,
        };

        if ($next === null) {
            throw new WorkflowConflictException('The warranty action is not valid in the current state.');
        }

        return $next;
    }
}
