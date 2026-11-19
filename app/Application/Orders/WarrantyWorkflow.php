<?php

namespace App\Application\Orders;

use App\Application\Audit\AuditLogger;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\WarrantyAction;
use App\Enums\WarrantyState;
use App\Exceptions\WorkflowConflictException;
use App\Models\Item;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\ThreadEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WarrantyWorkflow
{
    public function __construct(
        private readonly WarrantyActionGuard $guard,
        private readonly WarrantyStateMachine $stateMachine,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function create(Order $order, Item $item, WarrantyCommand $command): ProcessThread
    {
        if ($command->action !== WarrantyAction::CREATE_CYCLE) {
            throw new WorkflowConflictException('The warranty cycle must start with CREATE_WARRANTY_CYCLE.');
        }
        $this->guard->assertMayPerform($command->actor, $command->action);
        $this->validateEnvelope($command, false);
        if ($item->order_id !== $order->id) {
            throw new WorkflowConflictException('The item does not belong to this order.');
        }

        $hash = $this->requestHash($command, ['order_id' => $order->id, 'item_id' => $item->id]);

        return DB::transaction(function () use ($order, $item, $command, $hash): ProcessThread {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $lockedItem = Item::query()->where('order_id', $lockedOrder->id)->lockForUpdate()->findOrFail($item->id);
            $existing = ProcessThread::query()->where('creation_idempotency_key', $command->idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->creation_request_hash !== $hash || $existing->item_id !== $lockedItem->id) {
                    throw new WorkflowConflictException(__('app.workflow_errors.idempotency_reused'));
                }

                return $existing;
            }
            if ($lockedItem->active_warranty_thread_id !== null) {
                throw new WorkflowConflictException('This physical item already has an active warranty cycle.');
            }
            if ($lockedItem->active_shipment_id !== null) {
                throw new WorkflowConflictException('A warranty cycle cannot start while the item is in an active shipment.');
            }
            if (ProcessThread::query()->where('item_id', $lockedItem->id)->where('type', ProcessThreadType::WARRANTY)->whereNull('completed_at')->exists()) {
                throw new WorkflowConflictException('This physical item already has an active warranty cycle.');
            }

            $cycle = ((int) ProcessThread::query()
                ->where('item_id', $lockedItem->id)
                ->where('type', ProcessThreadType::WARRANTY)
                ->max('warranty_cycle')) + 1;
            $instance = ((int) ProcessThread::query()
                ->where('order_id', $lockedOrder->id)
                ->where('type', ProcessThreadType::WARRANTY)
                ->max('type_instance')) + 1;
            $autokluby = Organization::query()->where('code', OrganizationCode::AUTOKLUBY)->sole();
            $thread = ProcessThread::query()->create([
                'order_id' => $lockedOrder->id,
                'item_id' => $lockedItem->id,
                'type' => ProcessThreadType::WARRANTY,
                'type_instance' => $instance,
                'state' => WarrantyState::RECEIVED_AK->value,
                'responsible_party' => ResponsibleParty::AUTOKLUBY,
                'responsible_organization_id' => $autokluby->id,
                'warranty_cycle' => $cycle,
                'created_by' => $command->actor->id,
                'creation_idempotency_key' => $command->idempotencyKey,
                'creation_request_hash' => $hash,
                'lock_version' => 0,
            ]);
            $lockedItem->update([
                'active_warranty_thread_id' => $thread->id,
                'current_location' => OrganizationCode::AUTOKLUBY->value,
            ]);
            $event = $thread->events()->create([
                'order_id' => $lockedOrder->id,
                'action' => WarrantyAction::CREATE_CYCLE->value,
                'idempotency_key' => $command->idempotencyKey,
                'request_hash' => $hash,
                'from_state' => null,
                'to_state' => WarrantyState::RECEIVED_AK->value,
                'actor_type' => $command->actor->organization->code->value,
                'actor_user_id' => $command->actor->id,
                'actor_organization_id' => $command->actor->organization_id,
                'responsible_organization_id' => $autokluby->id,
                'metadata' => $this->metadata($thread),
            ]);
            $this->auditLogger->record('WARRANTY_CYCLE_CREATED', $thread, [], $this->snapshot($thread) + [
                'thread_event_id' => $event->id,
            ], null, $command->actor);

            return $thread;
        }, 3);
    }

    public function execute(ProcessThread $thread, WarrantyCommand $command): ThreadEvent
    {
        if (in_array($command->action, [WarrantyAction::CREATE_CYCLE], true)) {
            throw new WorkflowConflictException('CREATE_WARRANTY_CYCLE is only valid for a new cycle.');
        }
        $this->validateEnvelope($command, true);

        return DB::transaction(function () use ($thread, $command): ThreadEvent {
            $locked = ProcessThread::query()->where('type', ProcessThreadType::WARRANTY)->lockForUpdate()->findOrFail($thread->id);
            $item = Item::query()->lockForUpdate()->findOrFail($locked->item_id);
            $this->guard->assertMayPerform($command->actor, $command->action, $locked);
            $this->rejectStateInjection($command);
            $hash = $this->requestHash($command, ['thread_id' => $locked->id]);
            $existing = $locked->events()->where('idempotency_key', $command->idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $hash) {
                    throw new WorkflowConflictException(__('app.workflow_errors.idempotency_reused'));
                }

                return $existing;
            }
            if ($command->expectedLockVersion !== $locked->lock_version) {
                throw new WorkflowConflictException(__('app.workflow_errors.version_conflict'));
            }
            if ($locked->completed_at !== null) {
                throw new WorkflowConflictException('A completed warranty cycle is append-only and cannot be changed.');
            }

            $from = WarrantyState::from($locked->state);
            [$to, $reason] = $command->action === WarrantyAction::ADMIN_OVERRIDE
                ? $this->overrideTarget($command)
                : [$this->stateMachine->nextState($from, $command->action), null];
            [$party, $organization] = $this->responsibilityFor($to);
            $old = $this->snapshot($locked);
            $now = now();
            $updated = ProcessThread::query()
                ->whereKey($locked->id)
                ->where('lock_version', $locked->lock_version)
                ->update([
                    'state' => $to->value,
                    'responsible_party' => $party->value,
                    'responsible_organization_id' => $organization?->id,
                    'completed_at' => $to->isTerminal() ? $now : null,
                    'lock_version' => $locked->lock_version + 1,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new WorkflowConflictException(__('app.workflow_errors.version_conflict'));
            }
            $locked->refresh();
            if ($to->isTerminal()) {
                $cleared = Item::query()
                    ->whereKey($item->id)
                    ->where('active_warranty_thread_id', $locked->id)
                    ->update(['active_warranty_thread_id' => null, 'updated_at' => $now]);
                if ($cleared !== 1) {
                    throw new WorkflowConflictException('The active warranty cycle reservation is inconsistent.');
                }
            }

            $metadata = $this->metadata($locked) + Arr::only($command->data, ['shipment_id', 'shipment_public_number']);
            $event = $locked->events()->create([
                'order_id' => $locked->order_id,
                'action' => $command->action->value,
                'idempotency_key' => $command->idempotencyKey,
                'request_hash' => $hash,
                'from_state' => $from->value,
                'to_state' => $to->value,
                'actor_type' => $command->actor->organization->code->value,
                'actor_user_id' => $command->actor->id,
                'actor_organization_id' => $command->actor->organization_id,
                'responsible_organization_id' => $organization?->id,
                'reason' => $reason,
                'metadata' => $metadata,
            ]);
            $this->auditLogger->record(
                $command->action === WarrantyAction::ADMIN_OVERRIDE ? 'WARRANTY_ADMIN_OVERRIDE' : 'WARRANTY_ACTION',
                $locked,
                $old,
                $this->snapshot($locked) + ['thread_event_id' => $event->id, 'metadata' => $metadata],
                $reason,
                $command->actor,
            );

            return $event;
        }, 3);
    }

    private function validateEnvelope(WarrantyCommand $command, bool $versionRequired): void
    {
        Validator::make([
            'idempotency_key' => $command->idempotencyKey,
            'expected_lock_version' => $command->expectedLockVersion,
        ], [
            'idempotency_key' => ['required', 'string', 'max:128'],
            'expected_lock_version' => $versionRequired ? ['required', 'integer', 'min:0'] : ['nullable', 'integer', 'min:0'],
        ])->validate();
        if (trim($command->idempotencyKey) === '') {
            throw ValidationException::withMessages(['idempotency_key' => __('validation.required', ['attribute' => 'idempotency key'])]);
        }
    }

    private function rejectStateInjection(WarrantyCommand $command): void
    {
        if ($command->action !== WarrantyAction::ADMIN_OVERRIDE
            && Arr::hasAny($command->data, ['state', 'to_state', 'target_state'])) {
            throw ValidationException::withMessages(['state' => __('app.workflow_errors.target_state_forbidden')]);
        }
    }

    /** @return array{WarrantyState, string} */
    private function overrideTarget(WarrantyCommand $command): array
    {
        $validated = Validator::make($command->data, [
            'target_state' => ['required', Rule::enum(WarrantyState::class)],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();
        $reason = trim($validated['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('validation.required', ['attribute' => 'reason'])]);
        }

        return [WarrantyState::from($validated['target_state']), $reason];
    }

    /** @return array{ResponsibleParty, ?Organization} */
    private function responsibilityFor(WarrantyState $state): array
    {
        $code = match ($state) {
            WarrantyState::RECEIVED_AK, WarrantyState::READY_TO_PL,
            WarrantyState::SENT_TO_AK, WarrantyState::RECEIVED_AK_BACK => OrganizationCode::AUTOKLUBY,
            WarrantyState::SENT_TO_PL, WarrantyState::RECEIVED_PL,
            WarrantyState::REPAIR_COMPLETED, WarrantyState::READY_TO_AK => OrganizationCode::POLAND,
            WarrantyState::SENT_TO_CUSTOMER, WarrantyState::PICKED_UP_BY_CUSTOMER => null,
        };
        if ($code === null) {
            return [ResponsibleParty::CUSTOMER, null];
        }
        $organization = Organization::query()->where('code', $code)->sole();

        return [$code === OrganizationCode::AUTOKLUBY ? ResponsibleParty::AUTOKLUBY : ResponsibleParty::POLAND, $organization];
    }

    private function metadata(ProcessThread $thread): array
    {
        return [
            'warranty_cycle' => $thread->warranty_cycle,
            'item_id' => $thread->item_id,
        ];
    }

    private function snapshot(ProcessThread $thread): array
    {
        return [
            'order_id' => $thread->order_id,
            'item_id' => $thread->item_id,
            'warranty_cycle' => $thread->warranty_cycle,
            'state' => $thread->state,
            'responsible_party' => $thread->responsible_party->value,
            'responsible_organization_id' => $thread->responsible_organization_id,
            'lock_version' => $thread->lock_version,
            'completed_at' => $thread->completed_at?->toAtomString(),
        ];
    }

    private function requestHash(WarrantyCommand $command, array $context): string
    {
        $data = $command->data;
        ksort($data);
        ksort($context);

        return hash('sha256', json_encode([
            'action' => $command->action->value,
            'actor_id' => $command->actor->id,
            'data' => $data,
            'expected_lock_version' => $command->expectedLockVersion,
            'context' => $context,
        ], JSON_THROW_ON_ERROR));
    }
}
