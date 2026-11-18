<?php

namespace App\Application\Orders;

use App\Enums\FinanceEntryType;
use App\Enums\ItemRole;
use App\Models\ProcessThread;

final class PhaseFourQueuePresentation
{
    public function row(ProcessThread $thread): array
    {
        $finance = $thread->financeEntries;
        $originalDeposit = $finance->firstWhere('type', FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL);

        return [
            'thread' => $thread,
            'order' => $thread->order,
            'item' => $thread->order->items->firstWhere('role', ItemRole::CORE_RETURN),
            'deposit_status' => $this->depositStatus($thread),
            'deposit_amount' => $originalDeposit?->amount,
            'deposit_currency' => $originalDeposit?->currency,
            'next_action' => $this->nextAction($thread),
        ];
    }

    private function depositStatus(ProcessThread $thread): string
    {
        $finance = $thread->financeEntries;

        if ($finance->contains('type', FinanceEntryType::SUPPLIER_CREDIT_PAID)) {
            return 'paid';
        }
        if ($finance->contains('type', FinanceEntryType::SUPPLIER_CREDIT_NOTE_ISSUED)) {
            return 'credit_issued';
        }
        if ($finance->contains('type', FinanceEntryType::SUPPLIER_DEPOSIT_REDUCTION)) {
            return 'reduced';
        }
        if ($finance->contains('type', FinanceEntryType::SUPPLIER_DEPOSIT_RETURN_APPROVED)) {
            return 'return_approved';
        }
        if ($finance->contains('type', FinanceEntryType::SUPPLIER_DEPOSIT_NOT_CHARGED)) {
            return 'not_charged';
        }
        if ($finance->contains('type', FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL)) {
            return 'charged';
        }

        return 'unknown';
    }

    private function nextAction(ProcessThread $thread): string
    {
        return match ($thread->state) {
            'WAITING_CORE' => 'receive_at_autokluby',
            'CORE_RECEIVED_AK' => 'send_to_poland',
            'SENT_TO_PL' => 'receive_in_poland',
            'CORE_INSPECTION' => 'decide_deposit',
            'DEPOSIT_FULL_APPROVED', 'DEPOSIT_REDUCED_APPROVED' => 'upload_credit_note',
            'CREDIT_ISSUED' => 'confirm_payment',
            'CORE_SETTLED_NO_DEPOSIT', 'CREDIT_PAID' => 'completed',
            default => 'open_detail',
        };
    }
}
