<?php

namespace App\Application\Orders;

use App\Enums\ProcessThreadType;
use App\Enums\StockRebuildAction;
use App\Enums\StockRebuildState;
use App\Exceptions\WorkflowConflictException;
use Illuminate\Container\Container;

final class StockRebuildStateMachine
{
    public function nextState(
        ProcessThreadType $type,
        StockRebuildState $current,
        StockRebuildAction $action,
    ): StockRebuildState {
        $next = match ([$current, $action]) {
            [StockRebuildState::WAITING_PL_STOCK, StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE],
            [StockRebuildState::PL_SECURING_STOCK, StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE] => StockRebuildState::PL_STOCK_AVAILABLE,
            [StockRebuildState::WAITING_PL_STOCK, StockRebuildAction::SET_PL_STOCK_ETA],
            [StockRebuildState::PL_SECURING_STOCK, StockRebuildAction::SET_PL_STOCK_ETA] => StockRebuildState::PL_SECURING_STOCK,
            [StockRebuildState::PL_STOCK_AVAILABLE, StockRebuildAction::SEND_TO_AUTOKLUBY] => StockRebuildState::SENT_TO_AK,
            [StockRebuildState::SENT_TO_AK, StockRebuildAction::CONFIRM_AK_STOCK_RECEIPT] => StockRebuildState::RECEIVED_AK,
            [StockRebuildState::READY_AK_DISPATCH, StockRebuildAction::SEND_TO_CUSTOMER],
            [StockRebuildState::RECEIVED_AK, StockRebuildAction::SEND_TO_CUSTOMER] => $type === ProcessThreadType::STOCK_REQUEST ? StockRebuildState::SENT_CUSTOMER : null,
            [StockRebuildState::READY_AK_DISPATCH, StockRebuildAction::MARK_READY_PICKUP],
            [StockRebuildState::RECEIVED_AK, StockRebuildAction::MARK_READY_PICKUP] => $type === ProcessThreadType::STOCK_REQUEST ? StockRebuildState::READY_PICKUP : null,
            [StockRebuildState::READY_PICKUP, StockRebuildAction::CONFIRM_CUSTOMER_PICKUP] => $type === ProcessThreadType::STOCK_REQUEST ? StockRebuildState::PICKED_UP : null,
            default => null,
        };

        if ($next === null || ! in_array($type, [ProcessThreadType::STOCK_REQUEST, ProcessThreadType::STOCK_REPLENISHMENT], true)) {
            $parameters = ['action' => $action->value, 'state' => $current->value];
            $message = Container::getInstance()->bound('translator')
                ? __('app.workflow_errors.invalid_transition', $parameters)
                : "Action {$action->value} is not allowed from state {$current->value}.";

            throw new WorkflowConflictException($message);
        }

        return $next;
    }
}
