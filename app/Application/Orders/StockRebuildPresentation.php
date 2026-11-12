<?php

namespace App\Application\Orders;

use App\Contracts\ResolvesStockAvailability;
use App\Enums\ItemRole;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\StockAvailabilityStatus;
use App\Enums\StockRebuildAction;
use App\Models\Item;
use App\Models\Order;
use App\Models\ProcessThread;
use App\Models\User;
use Carbon\CarbonImmutable;

final class StockRebuildPresentation
{
    public function __construct(
        private readonly StockRebuildActionGuard $actionGuard,
        private readonly ResolvesStockAvailability $stockAvailability,
    ) {}

    /**
     * @return array{
     *   thread: ProcessThread,
     *   allowed_actions: array<int, StockRebuildAction>,
     *   next_action: ?StockRebuildAction,
     *   last_event: mixed
     * }
     */
    public function branch(Order $order, ProcessThread $thread, User $viewer): array
    {
        $thread->loadMissing('events');
        $actions = in_array($thread->type, [ProcessThreadType::STOCK_REQUEST, ProcessThreadType::STOCK_REPLENISHMENT], true)
            ? $this->actionGuard->allowedActions($order, $thread, $viewer)
            : [];

        return [
            'thread' => $thread,
            'allowed_actions' => $actions,
            'next_action' => $this->mainAction($actions),
            'last_event' => $thread->events->sortByDesc('id')->first(),
        ];
    }

    /**
     * @param  array<int, StockRebuildAction>  $actions
     */
    private function mainAction(array $actions): ?StockRebuildAction
    {
        foreach ([
            StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE,
            StockRebuildAction::SEND_TO_AUTOKLUBY,
            StockRebuildAction::CONFIRM_AK_STOCK_RECEIPT,
            StockRebuildAction::SEND_TO_CUSTOMER,
            StockRebuildAction::MARK_READY_PICKUP,
            StockRebuildAction::CONFIRM_CUSTOMER_PICKUP,
            StockRebuildAction::SET_PL_STOCK_ETA,
        ] as $preferred) {
            if (in_array($preferred, $actions, true)) {
                return $preferred;
            }
        }

        return null;
    }

    /** @return array{status: StockAvailabilityStatus, observed_at: mixed, source_reference: ?string} */
    public function stockInformation(Order $order): array
    {
        $confirmed = $order->threadEvents()->where('action', StockBranchDecisionService::ACTION)->latest('id')->first();
        if ($confirmed !== null) {
            return [
                'status' => StockAvailabilityStatus::from($confirmed->metadata['decision']),
                'observed_at' => isset($confirmed->metadata['observed_at'])
                    ? CarbonImmutable::parse($confirmed->metadata['observed_at'])
                    : null,
                'source_reference' => $confirmed->metadata['source_reference'] ?? null,
            ];
        }

        $decision = $this->stockAvailability->resolve($order);

        return [
            'status' => $decision->status,
            'observed_at' => $decision->observedAt,
            'source_reference' => $decision->sourceReference,
        ];
    }

    public function threadTypeForItem(Item $item): ?ProcessThreadType
    {
        return match ($item->role) {
            ItemRole::STOCK_ITEM => ProcessThreadType::STOCK_REQUEST,
            ItemRole::STOCK_REPLACEMENT => ProcessThreadType::STOCK_REPLENISHMENT,
            ItemRole::CORE_RETURN => ProcessThreadType::CORE_RETURN,
            default => null,
        };
    }

    /** @return array<int, string> */
    public function queueCodes(ProcessThread $thread, User $viewer): array
    {
        if ($viewer->organization->code === OrganizationCode::POLAND) {
            $code = match ($thread->state) {
                'WAITING_PL_STOCK' => 'pl_new',
                'PL_SECURING_STOCK' => 'pl_securing',
                'PL_STOCK_AVAILABLE' => 'pl_available',
                'SENT_TO_AK' => 'pl_sent',
                default => null,
            };

            return array_values(array_filter([
                $code,
                $thread->completed_at !== null ? 'completed' : null,
            ]));
        }

        return array_values(array_filter([
            $thread->state === 'PENDING_STOCK_CHECK' ? 'pending_stock' : null,
            $thread->type === ProcessThreadType::STOCK_REQUEST ? 'customer_fulfillment' : null,
            $thread->type === ProcessThreadType::STOCK_REPLENISHMENT ? 'stock_replenishment' : null,
            $thread->type === ProcessThreadType::CORE_RETURN ? 'core_return' : null,
            $thread->completed_at === null && $thread->responsible_party === ResponsibleParty::AUTOKLUBY ? 'waiting_ak' : null,
            $thread->completed_at === null && $thread->responsible_party === ResponsibleParty::POLAND ? 'waiting_poland' : null,
            $thread->completed_at !== null ? 'completed' : null,
        ]));
    }
}
