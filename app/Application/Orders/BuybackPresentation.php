<?php

namespace App\Application\Orders;

use App\Enums\BuybackAction;
use App\Enums\BuybackPriceType;
use App\Enums\BuybackState;
use App\Enums\OrganizationCode;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class BuybackPresentation
{
    public function __construct(private readonly BuybackActionGuard $guard) {}

    public function for(ProcessThread $thread, User $viewer): array
    {
        $state = BuybackState::from($thread->state);
        $allowed = [];
        foreach ($this->candidateActions($state) as $action) {
            if (in_array($action, [BuybackAction::SEND_TO_PL, BuybackAction::CONFIRM_PL_RECEIPT, BuybackAction::REQUIRE_INVOICE_TO_PL], true)) {
                continue;
            }
            try {
                $this->guard->assertMayPerform($viewer, $action, $thread);
                $allowed[] = $action;
            } catch (AuthorizationException) {
            }
        }
        $isAk = $viewer->organization->code === OrganizationCode::AUTOKLUBY;
        $transportAction = in_array($state, [BuybackState::READY_TO_PL, BuybackState::SENT_TO_PL], true)
            ? $this->nextAction($state) : null;
        if ($transportAction !== null) {
            try {
                $this->guard->assertMayPerform($viewer, $transportAction, $thread);
            } catch (AuthorizationException) {
                $transportAction = null;
            }
        }
        $next = $allowed[0] ?? $transportAction;
        $quotes = $thread->buybackPriceQuotes->groupBy(fn ($quote) => $quote->type->value);
        $poland = $quotes->get(BuybackPriceType::POLAND_OFFER->value, collect());
        $latestPoland = $poland->last();

        $events = $thread->events->sortByDesc('id')->values();
        if (! $isAk) {
            $safeActions = [
                BuybackAction::REQUEST_PL_OFFER, BuybackAction::SUBMIT_PL_OFFER,
                BuybackAction::DECIDE_PL_BUY, BuybackAction::SEND_TO_PL,
                BuybackAction::CONFIRM_PL_RECEIPT, BuybackAction::REQUIRE_INVOICE_TO_PL,
                BuybackAction::RECORD_INVOICE_TO_PL, BuybackAction::MARK_PAID_BY_PL,
            ];
            $events = $events->filter(fn ($event) => in_array($event->action, array_map(fn ($action) => $action->value, $safeActions), true))->values();
        }

        return [
            'thread' => $thread,
            'state' => $state,
            'display_state' => $isAk || in_array($state, [
                BuybackState::WAITING_FOR_PL_OFFER, BuybackState::PL_OFFERED,
                BuybackState::READY_TO_PL, BuybackState::SENT_TO_PL, BuybackState::RECEIVED_PL,
                BuybackState::INVOICE_TO_PL_REQUIRED, BuybackState::WAITING_FOR_PL_PAYMENT, BuybackState::PAID_BY_PL,
            ], true) ? $state->value : 'BUYBACK_WAITING_AK_DECISION',
            'next_action' => $next,
            'allowed_actions' => $allowed,
            'is_ak' => $isAk,
            'internal_quotes' => $isAk ? $quotes->get(BuybackPriceType::INTERNAL_AK->value, collect()) : collect(),
            'poland_quotes' => $poland,
            'final_quotes' => $isAk ? $quotes->get(BuybackPriceType::FINAL_CUSTOMER->value, collect()) : collect(),
            'recommended_minor' => $isAk && $latestPoland ? BuybackWorkflow::recommendedMinor($latestPoland->amount_minor) : null,
            'recommended_currency' => $isAk ? $latestPoland?->currency : null,
            'shipments' => $thread->shipmentItems->pluck('shipment')->filter()->unique('id')->values(),
            'documents' => $thread->documents,
            'finance_entries' => $isAk ? $thread->financeEntries : collect(),
            'events' => $events,
        ];
    }

    public function nextAction(BuybackState $state): ?BuybackAction
    {
        return $this->candidateActions($state)[0] ?? null;
    }

    private function candidateActions(BuybackState $state): array
    {
        return match ($state) {
            BuybackState::DRAFT => [BuybackAction::SET_INTERNAL_PRICE, BuybackAction::REQUEST_PL_OFFER],
            BuybackState::WAITING_FOR_PL_OFFER => [BuybackAction::SUBMIT_PL_OFFER],
            BuybackState::PL_OFFERED => [BuybackAction::SUBMIT_PL_OFFER, BuybackAction::REQUEST_FINAL_PRICE],
            BuybackState::WAITING_FOR_FINAL_PRICE => [BuybackAction::APPROVE_FINAL_PRICE],
            BuybackState::FINAL_PRICE_APPROVED => [BuybackAction::CONFIRM_CUSTOMER_OFFER],
            BuybackState::CUSTOMER_OFFER_READY => [BuybackAction::QUEUE_CUSTOMER_OFFER],
            BuybackState::OFFER_SENT => [BuybackAction::RECORD_CUSTOMER_ACCEPTANCE],
            BuybackState::CUSTOMER_ACCEPTED => [BuybackAction::RECEIVE_ITEM_AK],
            BuybackState::ITEM_RECEIVED_AK => [BuybackAction::CHECK_ITEM_AK],
            BuybackState::ITEM_CHECKED_AK => [BuybackAction::RETAIN_AK, BuybackAction::DECIDE_PL_BUY],
            BuybackState::READY_TO_PL => [BuybackAction::SEND_TO_PL],
            BuybackState::SENT_TO_PL => [BuybackAction::CONFIRM_PL_RECEIPT],
            BuybackState::RECEIVED_PL => [BuybackAction::REQUIRE_INVOICE_TO_PL],
            BuybackState::INVOICE_TO_PL_REQUIRED => [BuybackAction::RECORD_INVOICE_TO_PL],
            BuybackState::WAITING_FOR_PL_PAYMENT => [BuybackAction::MARK_PAID_BY_PL],
            BuybackState::RETAINED_AK, BuybackState::PAID_BY_PL => [],
        };
    }
}
