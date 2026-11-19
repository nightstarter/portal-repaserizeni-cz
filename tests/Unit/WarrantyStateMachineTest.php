<?php

namespace Tests\Unit;

use App\Application\Orders\WarrantyStateMachine;
use App\Enums\WarrantyAction;
use App\Enums\WarrantyState;
use App\Exceptions\WorkflowConflictException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WarrantyStateMachineTest extends TestCase
{
    #[DataProvider('transitions')]
    public function test_all_explicit_warranty_transitions(WarrantyState $from, WarrantyAction $action, WarrantyState $to): void
    {
        self::assertSame($to, (new WarrantyStateMachine)->nextState($from, $action));
    }

    public function test_skipped_and_repeated_transitions_are_rejected(): void
    {
        $machine = new WarrantyStateMachine;

        foreach ([
            [WarrantyState::RECEIVED_AK, WarrantyAction::SEND_TO_POLAND],
            [WarrantyState::READY_TO_PL, WarrantyAction::PREPARE_TO_POLAND],
            [WarrantyState::RECEIVED_PL, WarrantyAction::CONFIRM_POLAND_RECEIPT],
            [WarrantyState::RECEIVED_AK_BACK, WarrantyAction::SEND_TO_AUTOKLUBY],
        ] as [$state, $action]) {
            try {
                $machine->nextState($state, $action);
                self::fail('Expected invalid warranty transition.');
            } catch (WorkflowConflictException) {
                self::assertTrue(true);
            }
        }
    }

    public static function transitions(): array
    {
        return [
            [WarrantyState::RECEIVED_AK, WarrantyAction::PREPARE_TO_POLAND, WarrantyState::READY_TO_PL],
            [WarrantyState::READY_TO_PL, WarrantyAction::SEND_TO_POLAND, WarrantyState::SENT_TO_PL],
            [WarrantyState::SENT_TO_PL, WarrantyAction::CONFIRM_POLAND_RECEIPT, WarrantyState::RECEIVED_PL],
            [WarrantyState::RECEIVED_PL, WarrantyAction::COMPLETE_REPAIR, WarrantyState::REPAIR_COMPLETED],
            [WarrantyState::REPAIR_COMPLETED, WarrantyAction::PREPARE_TO_AUTOKLUBY, WarrantyState::READY_TO_AK],
            [WarrantyState::READY_TO_AK, WarrantyAction::SEND_TO_AUTOKLUBY, WarrantyState::SENT_TO_AK],
            [WarrantyState::SENT_TO_AK, WarrantyAction::CONFIRM_AUTOKLUBY_RECEIPT, WarrantyState::RECEIVED_AK_BACK],
            [WarrantyState::RECEIVED_AK_BACK, WarrantyAction::SEND_TO_CUSTOMER, WarrantyState::SENT_TO_CUSTOMER],
            [WarrantyState::RECEIVED_AK_BACK, WarrantyAction::CONFIRM_CUSTOMER_PICKUP, WarrantyState::PICKED_UP_BY_CUSTOMER],
        ];
    }
}
