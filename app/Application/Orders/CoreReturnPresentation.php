<?php

namespace App\Application\Orders;

use App\Enums\CoreReturnAction;
use App\Enums\CoreReturnState;
use App\Enums\DocumentStatus;
use App\Enums\FinanceEntryType;
use App\Enums\ItemRole;
use App\Enums\OrganizationCode;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\ProcessThread;
use App\Models\User;

final class CoreReturnPresentation
{
    public function for(Order $order, ProcessThread $thread, User $viewer): array
    {
        $thread->loadMissing(['events', 'coreReturnInspections', 'financeEntries.actorUser', 'documents.uploader']);
        $state = CoreReturnState::from($thread->state);
        $isAk = $viewer->organization->code === OrganizationCode::AUTOKLUBY;
        $isPl = $viewer->organization->code === OrganizationCode::POLAND;
        $isAdmin = $viewer->role === UserRole::AK_ADMIN;
        $actions = [];
        if ($state === CoreReturnState::WAITING_CORE && $isAk) {
            $actions[] = CoreReturnAction::CONFIRM_CORE_RECEIPT_AK;
        }
        if ($state === CoreReturnState::CORE_INSPECTION && ($isPl || $isAdmin)) {
            if ($thread->financeEntries->contains('type', FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL)) {
                $actions = [CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, CoreReturnAction::APPROVE_REDUCED_DEPOSIT_RETURN];
            }
            if ($thread->financeEntries->contains('type', FinanceEntryType::SUPPLIER_DEPOSIT_NOT_CHARGED)) {
                $actions[] = CoreReturnAction::CONFIRM_NO_SUPPLIER_DEPOSIT;
            }
        }
        if ($state === CoreReturnState::CREDIT_ISSUED && ($isPl || $isAdmin)) {
            $actions[] = CoreReturnAction::CONFIRM_CREDIT_PAID;
        }
        $item = $order->items->firstWhere('role', ItemRole::CORE_RETURN);

        return [
            'thread' => $thread,
            'last_event' => $thread->events->sortByDesc('id')->first(),
            'item' => $item,
            'inspection' => $thread->coreReturnInspections->sortByDesc('id')->first(),
            'allowed_actions' => $actions,
            'next_action' => $actions[0] ?? null,
            'documents' => $thread->documents->sortByDesc('id'),
            'active_documents' => $thread->documents->where('status', DocumentStatus::ACTIVE),
            'finance' => $thread->financeEntries->sortBy('id'),
            'original_deposit' => $thread->financeEntries->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL)->sortByDesc('id')->first(),
            'approved_return' => $thread->financeEntries->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_RETURN_APPROVED)->sortByDesc('id')->first(),
            'reduction' => $thread->financeEntries->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_REDUCTION)->sortByDesc('id')->first(),
        ];
    }
}
