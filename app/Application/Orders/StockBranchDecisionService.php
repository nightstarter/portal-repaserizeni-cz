<?php

namespace App\Application\Orders;

use App\Application\Audit\AuditLogger;
use App\Contracts\ResolvesStockAvailability;
use App\Enums\ItemRole;
use App\Enums\OrderType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\StockAvailabilityStatus;
use App\Enums\StockRebuildState;
use App\Exceptions\WorkflowConflictException;
use App\Models\Item;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\ThreadEvent;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class StockBranchDecisionService
{
    public const ACTION = 'STOCK_AVAILABILITY_DECIDED';

    public function __construct(
        private readonly ResolvesStockAvailability $resolver,
        private readonly AuditLogger $auditLogger,
        private readonly OrderCompletionService $completion,
    ) {}

    public function decide(Order $order, string $idempotencyKey): ?ThreadEvent
    {
        Validator::make(['idempotency_key' => $idempotencyKey], [
            'idempotency_key' => ['required', 'string', 'max:128', function (string $attribute, mixed $value, \Closure $fail): void {
                if (trim((string) $value) === '') {
                    $fail(__('validation.required', ['attribute' => __('validation.attributes.idempotency_key')]));
                }
            }],
        ])->validate();
        $decision = $this->resolver->resolve($order);

        if (! $decision->status->isConclusive()) {
            return null;
        }

        $result = DB::transaction(function () use ($order, $idempotencyKey, $decision): ThreadEvent|false {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->order_type !== OrderType::STOCK_REBUILD) {
                throw new DomainException('Stock availability can only be decided for STOCK_REBUILD.');
            }

            $request = ProcessThread::query()
                ->where('order_id', $lockedOrder->id)
                ->where('type', ProcessThreadType::STOCK_REQUEST)
                ->lockForUpdate()
                ->sole();
            $existing = $request->events()->where('action', self::ACTION)->first();

            if ($existing !== null) {
                $existingStatus = StockAvailabilityStatus::tryFrom((string) ($existing->metadata['decision'] ?? ''));
                if ($existingStatus === $decision->status) {
                    return $existing;
                }

                $this->auditLogger->record(
                    'STOCK_AVAILABILITY_CONFLICT',
                    $request,
                    ['decision' => $existingStatus?->value],
                    ['attempted_decision' => $decision->status->value, 'source_reference' => $decision->sourceReference],
                    'Conflicting trusted stock decision rejected.',
                );

                return false;
            }

            if ($request->state !== StockRebuildState::PENDING_STOCK_CHECK->value) {
                $this->auditLogger->record(
                    'STOCK_AVAILABILITY_CONFLICT',
                    $request,
                    ['state' => $request->state],
                    ['attempted_decision' => $decision->status->value],
                    'Stock request is no longer pending a stock decision.',
                );

                return false;
            }

            $organizations = Organization::query()
                ->whereIn('code', [OrganizationCode::AUTOKLUBY, OrganizationCode::POLAND])
                ->get()
                ->keyBy(fn (Organization $organization): string => $organization->code->value);
            $autokluby = $organizations->get(OrganizationCode::AUTOKLUBY->value);
            $poland = $organizations->get(OrganizationCode::POLAND->value);
            if ($autokluby === null || $poland === null) {
                throw new DomainException('Required organizations are missing.');
            }

            $available = $decision->status === StockAvailabilityStatus::AVAILABLE_IN_AK;
            $next = $available ? StockRebuildState::READY_AK_DISPATCH : StockRebuildState::WAITING_PL_STOCK;
            $party = $available ? ResponsibleParty::AUTOKLUBY : ResponsibleParty::POLAND;
            $responsibleOrganizationId = $available ? $autokluby->id : $poland->id;
            $now = now();
            $request->update([
                'state' => $next->value,
                'responsible_party' => $party,
                'responsible_organization_id' => $responsibleOrganizationId,
                'lock_version' => $request->lock_version + 1,
            ]);
            $metadata = [
                'decision' => $decision->status->value,
                'source_reference' => $decision->sourceReference,
                'observed_at' => $decision->observedAt?->toAtomString(),
            ];
            $event = $request->events()->create([
                'order_id' => $lockedOrder->id,
                'action' => self::ACTION,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR)),
                'from_state' => StockRebuildState::PENDING_STOCK_CHECK->value,
                'to_state' => $next->value,
                'actor_type' => ResponsibleParty::SYSTEM->value,
                'responsible_organization_id' => $responsibleOrganizationId,
                'metadata' => $metadata,
            ]);

            if ($available) {
                $this->createThread($lockedOrder, ProcessThreadType::STOCK_REPLENISHMENT, StockRebuildState::WAITING_PL_STOCK, ResponsibleParty::POLAND, $poland->id, $decision);
            }
            $this->createThread($lockedOrder, ProcessThreadType::CORE_RETURN, StockRebuildState::WAITING_CORE, ResponsibleParty::CUSTOMER, null, $decision);

            $this->createItem($lockedOrder, ItemRole::STOCK_ITEM, $available ? OrganizationCode::AUTOKLUBY->value : OrganizationCode::POLAND->value);
            if ($available) {
                $this->createItem($lockedOrder, ItemRole::STOCK_REPLACEMENT, OrganizationCode::POLAND->value);
            }
            $this->createItem($lockedOrder, ItemRole::CORE_RETURN, ResponsibleParty::CUSTOMER->value);

            $this->completion->refresh($lockedOrder);
            $this->auditLogger->record(
                'STOCK_AVAILABILITY_DECIDED',
                $request,
                ['state' => StockRebuildState::PENDING_STOCK_CHECK->value],
                [
                    'state' => $next->value,
                    'decision' => $decision->status->value,
                    'source_reference' => $decision->sourceReference,
                    'thread_event_id' => $event->id,
                    'materialized_at' => $now->toAtomString(),
                ],
            );

            return $event;
        }, 3);

        if ($result === false) {
            throw new WorkflowConflictException(__('app.workflow_errors.stock_decision_conflict'));
        }

        return $result;
    }

    private function createThread(
        Order $order,
        ProcessThreadType $type,
        StockRebuildState $state,
        ResponsibleParty $party,
        ?int $organizationId,
        StockAvailabilityDecision $decision,
    ): void {
        $thread = ProcessThread::query()->create([
            'order_id' => $order->id,
            'type' => $type,
            'state' => $state->value,
            'responsible_party' => $party,
            'responsible_organization_id' => $organizationId,
            'vendor_routing' => $order->routing,
        ]);
        $thread->events()->create([
            'order_id' => $order->id,
            'action' => 'THREAD_CREATED',
            'to_state' => $state->value,
            'actor_type' => ResponsibleParty::SYSTEM->value,
            'responsible_organization_id' => $organizationId,
            'metadata' => [
                'scenario' => $decision->status === StockAvailabilityStatus::AVAILABLE_IN_AK ? 'STOCK_DECIN' : 'STOCK_POLAND',
                'source_reference' => $decision->sourceReference,
            ],
        ]);
    }

    private function createItem(Order $order, ItemRole $role, string $location): void
    {
        Item::query()->create([
            'order_id' => $order->id,
            'role' => $role,
            'current_location' => $location,
        ]);
    }
}
