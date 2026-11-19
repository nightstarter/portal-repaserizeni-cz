<?php

namespace App\Application\Orders;

use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\WarrantyAction;
use App\Enums\WarrantyState;
use App\Exceptions\WorkflowConflictException;
use App\Models\Order;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

final class WarrantyPresentation
{
    private const TRANSPORT_ACTIONS = [
        WarrantyAction::SEND_TO_POLAND,
        WarrantyAction::CONFIRM_POLAND_RECEIPT,
        WarrantyAction::SEND_TO_AUTOKLUBY,
        WarrantyAction::CONFIRM_AUTOKLUBY_RECEIPT,
    ];

    public function __construct(
        private readonly WarrantyActionGuard $guard,
        private readonly WarrantyStateMachine $stateMachine,
    ) {}

    /** @return array<string, mixed> */
    public function for(ProcessThread $thread, User $viewer): array
    {
        $state = WarrantyState::from($thread->state);
        $candidates = $this->candidateActions($state);
        $nextAction = $candidates[0] ?? null;
        $allowed = [];
        foreach ($candidates as $candidate) {
            if (in_array($candidate, self::TRANSPORT_ACTIONS, true)) {
                continue;
            }
            try {
                $this->guard->assertRoleMayPerform($viewer, $candidate);
                $this->stateMachine->nextState($state, $candidate);
                $allowed[] = $candidate;
            } catch (AuthorizationException|WorkflowConflictException) {
                // The write path repeats the same role and transition checks.
            }
        }

        $shipments = $thread->shipmentItems->pluck('shipment')->filter()->unique('id')->values();

        return [
            'thread' => $thread,
            'state' => $state,
            'next_action' => $nextAction,
            'allowed_actions' => $allowed,
            'transport_action' => $nextAction !== null && in_array($nextAction, self::TRANSPORT_ACTIONS, true) ? $nextAction : null,
            'last_event' => $thread->latestEvent ?? $thread->events->sortByDesc('id')->first(),
            'shipments' => $shipments,
            'active_shipment' => $shipments->first(
                fn ($shipment): bool => in_array($shipment->status->value, ['PREPARED', 'SENT', 'TRANSPORT_ERROR'], true),
            ),
            'timeline' => $this->timeline($thread, $viewer),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function collection(Collection $threads, User $viewer): Collection
    {
        return $threads->map(fn (ProcessThread $thread): array => $this->for($thread, $viewer));
    }

    /** @return Collection<int, Collection<int, array<string, mixed>>> */
    public function forOrder(Order $order, User $viewer): Collection
    {
        $threads = $order->processThreads()
            ->visibleTo($viewer)
            ->where('type', ProcessThreadType::WARRANTY)
            ->with([
                'item', 'responsibleOrganization', 'latestEvent',
                'events.actorUser', 'events.actorOrganization', 'shipmentItems.shipment',
            ])
            ->orderBy('item_id')
            ->orderBy('warranty_cycle')
            ->get();

        return $this->collection($threads, $viewer)->groupBy(
            fn (array $row): int => $row['thread']->item_id,
        );
    }

    public function nextAction(WarrantyState $state): ?WarrantyAction
    {
        return $this->candidateActions($state)[0] ?? null;
    }

    /** @return array<int, WarrantyAction> */
    private function candidateActions(WarrantyState $state): array
    {
        return match ($state) {
            WarrantyState::RECEIVED_AK => [WarrantyAction::PREPARE_TO_POLAND],
            WarrantyState::READY_TO_PL => [WarrantyAction::SEND_TO_POLAND],
            WarrantyState::SENT_TO_PL => [WarrantyAction::CONFIRM_POLAND_RECEIPT],
            WarrantyState::RECEIVED_PL => [WarrantyAction::COMPLETE_REPAIR],
            WarrantyState::REPAIR_COMPLETED => [WarrantyAction::PREPARE_TO_AUTOKLUBY],
            WarrantyState::READY_TO_AK => [WarrantyAction::SEND_TO_AUTOKLUBY],
            WarrantyState::SENT_TO_AK => [WarrantyAction::CONFIRM_AUTOKLUBY_RECEIPT],
            WarrantyState::RECEIVED_AK_BACK => [WarrantyAction::SEND_TO_CUSTOMER, WarrantyAction::CONFIRM_CUSTOMER_PICKUP],
            WarrantyState::SENT_TO_CUSTOMER, WarrantyState::PICKED_UP_BY_CUSTOMER => [],
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function timeline(ProcessThread $thread, User $viewer): array
    {
        $isAutokluby = $viewer->organization->code === OrganizationCode::AUTOKLUBY;

        return $thread->events
            ->sortByDesc('id')
            ->map(static function ($event) use ($isAutokluby): array {
                $metadata = collect((array) $event->metadata)
                    ->only(['shipment_public_number'])
                    ->filter(static fn (mixed $value): bool => is_scalar($value) || $value === null)
                    ->all();

                return [
                    'performed_at' => $event->performed_at,
                    'action' => $event->action,
                    'from_state' => $event->from_state,
                    'to_state' => $event->to_state,
                    'actor_organization' => $event->actorOrganization?->code?->value ?? $event->actor_type,
                    'actor_name' => $event->actorUser?->name,
                    'reason' => $isAutokluby ? $event->reason : null,
                    'metadata' => $metadata,
                ];
            })
            ->values()
            ->all();
    }
}
