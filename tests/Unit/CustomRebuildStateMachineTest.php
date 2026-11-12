<?php

namespace Tests\Unit;

use App\Application\Orders\CustomRebuildStateMachine;
use App\Enums\CustomRebuildAction;
use App\Enums\CustomRebuildState;
use App\Exceptions\WorkflowConflictException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CustomRebuildStateMachineTest extends TestCase
{
    #[DataProvider('allowedTransitions')]
    public function test_allowed_transition(
        CustomRebuildState $from,
        CustomRebuildAction $action,
        CustomRebuildState $to,
        ?CustomRebuildAction $entryAction = null,
    ): void {
        self::assertSame($to, (new CustomRebuildStateMachine)->nextState($from, $action, $entryAction));
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_every_forbidden_jump_is_rejected(
        CustomRebuildState $from,
        CustomRebuildAction $action,
        ?CustomRebuildAction $entryAction,
    ): void {
        $this->expectException(WorkflowConflictException::class);

        (new CustomRebuildStateMachine)->nextState($from, $action, $entryAction);
    }

    public static function allowedTransitions(): array
    {
        return [
            [CustomRebuildState::WAITING_CUSTOMER_ITEM, CustomRebuildAction::CONFIRM_AK_RECEIPT, CustomRebuildState::RECEIVED_AK],
            [CustomRebuildState::RECEIVED_AK, CustomRebuildAction::SEND_TO_POLAND, CustomRebuildState::SENT_TO_PL, CustomRebuildAction::CONFIRM_AK_RECEIPT],
            [CustomRebuildState::SENT_TO_PL, CustomRebuildAction::CONFIRM_PL_RECEIPT, CustomRebuildState::RECEIVED_PL_REPAIR],
            [CustomRebuildState::RECEIVED_PL_REPAIR, CustomRebuildAction::MARK_REPAIRED_PL, CustomRebuildState::REPAIRED_PL],
            [CustomRebuildState::REPAIRED_PL, CustomRebuildAction::SEND_TO_AUTOKLUBY, CustomRebuildState::SENT_TO_AK],
            [CustomRebuildState::SENT_TO_AK, CustomRebuildAction::CONFIRM_AK_RETURN_RECEIPT, CustomRebuildState::RECEIVED_AK],
            [CustomRebuildState::RECEIVED_AK, CustomRebuildAction::SEND_TO_CUSTOMER, CustomRebuildState::SENT_CUSTOMER, CustomRebuildAction::CONFIRM_AK_RETURN_RECEIPT],
            [CustomRebuildState::RECEIVED_AK, CustomRebuildAction::MARK_READY_PICKUP, CustomRebuildState::READY_PICKUP, CustomRebuildAction::CONFIRM_AK_RETURN_RECEIPT],
            [CustomRebuildState::READY_PICKUP, CustomRebuildAction::CONFIRM_CUSTOMER_PICKUP, CustomRebuildState::PICKED_UP],
        ];
    }

    public static function forbiddenTransitions(): array
    {
        $allowed = [];
        foreach (self::allowedTransitions() as $transition) {
            [$from, $action, , $entryAction] = array_pad($transition, 4, null);
            $allowed[$from->value.'|'.$action->value.'|'.($entryAction?->value ?? '')] = true;
        }

        $cases = [];
        foreach (CustomRebuildState::cases() as $state) {
            foreach (CustomRebuildAction::cases() as $action) {
                if ($action === CustomRebuildAction::ADMIN_OVERRIDE) {
                    continue;
                }

                $entryActions = $state === CustomRebuildState::RECEIVED_AK
                    ? [CustomRebuildAction::CONFIRM_AK_RECEIPT, CustomRebuildAction::CONFIRM_AK_RETURN_RECEIPT, null]
                    : [null];
                foreach ($entryActions as $entryAction) {
                    $key = $state->value.'|'.$action->value.'|'.($entryAction?->value ?? '');
                    if (! isset($allowed[$key])) {
                        $cases[$key] = [$state, $action, $entryAction];
                    }
                }
            }
        }

        return $cases;
    }
}
