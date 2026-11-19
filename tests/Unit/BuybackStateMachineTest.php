<?php

namespace Tests\Unit;

use App\Application\Orders\BuybackStateMachine;
use App\Enums\BuybackAction;
use App\Enums\BuybackState;
use App\Exceptions\WorkflowConflictException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BuybackStateMachineTest extends TestCase
{
    #[DataProvider('transitions')]
    public function test_explicit_transition(BuybackState $from, BuybackAction $action, BuybackState $to): void
    {
        self::assertSame($to, (new BuybackStateMachine)->nextState($from, $action));
    }

    public function test_arbitrary_jump_is_rejected(): void
    {
        $this->expectException(WorkflowConflictException::class);
        (new BuybackStateMachine)->nextState(BuybackState::DRAFT, BuybackAction::MARK_PAID_BY_PL);
    }

    public static function transitions(): array
    {
        return [
            [BuybackState::DRAFT, BuybackAction::REQUEST_PL_OFFER, BuybackState::WAITING_FOR_PL_OFFER],
            [BuybackState::WAITING_FOR_PL_OFFER, BuybackAction::SUBMIT_PL_OFFER, BuybackState::PL_OFFERED],
            [BuybackState::PL_OFFERED, BuybackAction::SUBMIT_PL_OFFER, BuybackState::PL_OFFERED],
            [BuybackState::PL_OFFERED, BuybackAction::REQUEST_FINAL_PRICE, BuybackState::WAITING_FOR_FINAL_PRICE],
            [BuybackState::WAITING_FOR_FINAL_PRICE, BuybackAction::APPROVE_FINAL_PRICE, BuybackState::FINAL_PRICE_APPROVED],
            [BuybackState::FINAL_PRICE_APPROVED, BuybackAction::CONFIRM_CUSTOMER_OFFER, BuybackState::CUSTOMER_OFFER_READY],
            [BuybackState::CUSTOMER_OFFER_READY, BuybackAction::QUEUE_CUSTOMER_OFFER, BuybackState::CUSTOMER_OFFER_READY],
            [BuybackState::OFFER_SENT, BuybackAction::RECORD_CUSTOMER_ACCEPTANCE, BuybackState::CUSTOMER_ACCEPTED],
            [BuybackState::CUSTOMER_ACCEPTED, BuybackAction::RECEIVE_ITEM_AK, BuybackState::ITEM_RECEIVED_AK],
            [BuybackState::ITEM_RECEIVED_AK, BuybackAction::CHECK_ITEM_AK, BuybackState::ITEM_CHECKED_AK],
            [BuybackState::ITEM_CHECKED_AK, BuybackAction::RETAIN_AK, BuybackState::RETAINED_AK],
            [BuybackState::ITEM_CHECKED_AK, BuybackAction::DECIDE_PL_BUY, BuybackState::READY_TO_PL],
            [BuybackState::READY_TO_PL, BuybackAction::SEND_TO_PL, BuybackState::SENT_TO_PL],
            [BuybackState::SENT_TO_PL, BuybackAction::CONFIRM_PL_RECEIPT, BuybackState::RECEIVED_PL],
            [BuybackState::RECEIVED_PL, BuybackAction::REQUIRE_INVOICE_TO_PL, BuybackState::INVOICE_TO_PL_REQUIRED],
            [BuybackState::INVOICE_TO_PL_REQUIRED, BuybackAction::RECORD_INVOICE_TO_PL, BuybackState::WAITING_FOR_PL_PAYMENT],
            [BuybackState::WAITING_FOR_PL_PAYMENT, BuybackAction::MARK_PAID_BY_PL, BuybackState::PAID_BY_PL],
        ];
    }
}
