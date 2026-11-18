<?php

namespace App\Application\Finance;

use App\Models\FinanceEntry;
use App\Models\Order;
use App\Models\ProcessThread;
use App\Models\ThreadEvent;
use App\Models\User;

final class FinancialLedgerService
{
    /** @return array<int, FinanceEntry> */
    public function append(
        Order $order,
        ProcessThread $thread,
        ?ThreadEvent $event,
        int $auditId,
        User $actor,
        string $idempotencyKey,
        string $requestHash,
        array $entries,
    ): array {
        $created = [];
        foreach ($entries as $index => $entry) {
            $created[] = FinanceEntry::query()->create([
                'order_id' => $order->id,
                'process_thread_id' => $thread->id,
                'thread_event_id' => $event?->id,
                'audit_log_id' => $auditId,
                'type' => $entry['type'],
                'amount' => $entry['amount'],
                'currency' => $entry['currency'],
                'status' => $entry['status'],
                'actor_user_id' => $actor->id,
                'actor_organization_id' => $actor->organization_id,
                'reason' => $entry['reason'] ?? null,
                'idempotency_key' => $idempotencyKey.':'.($index + 1),
                'request_hash' => $requestHash,
                'corrects_entry_id' => $entry['corrects_entry_id'] ?? null,
            ]);
        }

        return $created;
    }
}
