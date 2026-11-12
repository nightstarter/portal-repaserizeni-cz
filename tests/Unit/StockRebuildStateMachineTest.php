<?php

namespace Tests\Unit;

use App\Application\Orders\StockRebuildStateMachine;
use App\Enums\ProcessThreadType;
use App\Enums\StockRebuildAction;
use App\Enums\StockRebuildState;
use App\Exceptions\WorkflowConflictException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StockRebuildStateMachineTest extends TestCase
{
    #[DataProvider('allowedTransitions')]
    public function test_allowed_stock_transition(
        ProcessThreadType $type,
        StockRebuildState $from,
        StockRebuildAction $action,
        StockRebuildState $to,
    ): void {
        self::assertSame($to, (new StockRebuildStateMachine)->nextState($type, $from, $action));
    }

    public function test_forbidden_jump_is_rejected(): void
    {
        $this->expectException(WorkflowConflictException::class);

        (new StockRebuildStateMachine)->nextState(
            ProcessThreadType::STOCK_REQUEST,
            StockRebuildState::WAITING_PL_STOCK,
            StockRebuildAction::SEND_TO_AUTOKLUBY,
        );
    }

    public static function allowedTransitions(): array
    {
        $polish = [
            [StockRebuildState::WAITING_PL_STOCK, StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE, StockRebuildState::PL_STOCK_AVAILABLE],
            [StockRebuildState::WAITING_PL_STOCK, StockRebuildAction::SET_PL_STOCK_ETA, StockRebuildState::PL_SECURING_STOCK],
            [StockRebuildState::PL_SECURING_STOCK, StockRebuildAction::SET_PL_STOCK_ETA, StockRebuildState::PL_SECURING_STOCK],
            [StockRebuildState::PL_SECURING_STOCK, StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE, StockRebuildState::PL_STOCK_AVAILABLE],
            [StockRebuildState::PL_STOCK_AVAILABLE, StockRebuildAction::SEND_TO_AUTOKLUBY, StockRebuildState::SENT_TO_AK],
            [StockRebuildState::SENT_TO_AK, StockRebuildAction::CONFIRM_AK_STOCK_RECEIPT, StockRebuildState::RECEIVED_AK],
        ];
        $cases = [];
        foreach ([ProcessThreadType::STOCK_REQUEST, ProcessThreadType::STOCK_REPLENISHMENT] as $type) {
            foreach ($polish as $transition) {
                $cases[] = [$type, ...$transition];
            }
        }
        $cases[] = [ProcessThreadType::STOCK_REQUEST, StockRebuildState::READY_AK_DISPATCH, StockRebuildAction::SEND_TO_CUSTOMER, StockRebuildState::SENT_CUSTOMER];
        $cases[] = [ProcessThreadType::STOCK_REQUEST, StockRebuildState::RECEIVED_AK, StockRebuildAction::SEND_TO_CUSTOMER, StockRebuildState::SENT_CUSTOMER];
        $cases[] = [ProcessThreadType::STOCK_REQUEST, StockRebuildState::READY_AK_DISPATCH, StockRebuildAction::MARK_READY_PICKUP, StockRebuildState::READY_PICKUP];
        $cases[] = [ProcessThreadType::STOCK_REQUEST, StockRebuildState::RECEIVED_AK, StockRebuildAction::MARK_READY_PICKUP, StockRebuildState::READY_PICKUP];
        $cases[] = [ProcessThreadType::STOCK_REQUEST, StockRebuildState::READY_PICKUP, StockRebuildAction::CONFIRM_CUSTOMER_PICKUP, StockRebuildState::PICKED_UP];

        return $cases;
    }
}
