<?php

namespace App\Application\Orders;

use App\Application\Audit\AuditLogger;
use App\Enums\ProcessThreadType;
use App\Exceptions\WorkflowConflictException;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\ThreadEvent;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ThreadWorkflowEngine
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly OrderCompletionService $completion,
    ) {}

    /**
     * @param  Closure(Order, ProcessThread): void  $authorize
     * @param  Closure(Order, ProcessThread): ThreadTransition  $transition
     */
    public function execute(
        Order $order,
        ProcessThreadType $threadType,
        ThreadWorkflowCommand $command,
        Closure $authorize,
        Closure $transition,
        string $auditAction,
    ): ThreadEvent {
        $this->validateEnvelope($command);

        return DB::transaction(function () use ($order, $threadType, $command, $authorize, $transition, $auditAction): ThreadEvent {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $thread = ProcessThread::query()
                ->where('order_id', $lockedOrder->id)
                ->where('type', $threadType)
                ->lockForUpdate()
                ->sole();

            $authorize($lockedOrder, $thread);
            $requestHash = $this->requestHash($command);
            $existing = $thread->events()->where('idempotency_key', $command->idempotencyKey)->first();

            if ($existing !== null) {
                if ($existing->request_hash !== $requestHash) {
                    throw new WorkflowConflictException(__('app.workflow_errors.idempotency_reused'));
                }

                return $existing;
            }

            if ($command->expectedLockVersion !== null && $command->expectedLockVersion !== $thread->lock_version) {
                throw new WorkflowConflictException(__('app.workflow_errors.version_conflict'));
            }

            $oldValues = $this->auditSnapshot($lockedOrder, $thread);
            $result = $transition($lockedOrder, $thread);
            $organization = $result->responsibleOrganization === null
                ? null
                : Organization::query()->where('code', $result->responsibleOrganization)->sole();
            $now = now();
            $values = [
                'state' => $result->toState,
                'responsible_party' => $result->responsibleParty->value,
                'responsible_organization_id' => $organization?->id,
                'completed_at' => $result->completed ? $now : null,
                'lock_version' => $thread->lock_version + 1,
                'updated_at' => $now,
            ];
            if (! $result->keepEta) {
                $values['eta'] = $result->eta;
            }

            $updated = ProcessThread::query()
                ->whereKey($thread->id)
                ->where('lock_version', $thread->lock_version)
                ->update($values);

            if ($updated !== 1) {
                throw new WorkflowConflictException(__('app.workflow_errors.version_conflict'));
            }

            $thread->refresh();
            $this->completion->refresh($lockedOrder);

            $event = $thread->events()->create([
                'order_id' => $lockedOrder->id,
                'action' => $command->action,
                'idempotency_key' => $command->idempotencyKey,
                'request_hash' => $requestHash,
                'from_state' => $oldValues['state'],
                'to_state' => $result->toState,
                'actor_type' => $command->actor->organization->code->value,
                'actor_user_id' => $command->actor->id,
                'actor_organization_id' => $command->actor->organization_id,
                'responsible_organization_id' => $organization?->id,
                'reason' => $result->reason,
                'metadata' => $result->metadata,
            ]);

            $this->auditLogger->record(
                $auditAction,
                $thread,
                $oldValues,
                $this->auditSnapshot($lockedOrder->fresh(), $thread->fresh()) + [
                    'thread_event_id' => $event->id,
                    'metadata' => $result->metadata,
                ],
                $result->reason,
                $command->actor,
            );

            return $event;
        }, 3);
    }

    private function validateEnvelope(ThreadWorkflowCommand $command): void
    {
        Validator::make(['idempotency_key' => $command->idempotencyKey], [
            'idempotency_key' => ['required', 'string', 'max:128', function (string $attribute, mixed $value, Closure $fail): void {
                if (trim((string) $value) === '') {
                    $fail(__('validation.required', ['attribute' => __('validation.attributes.idempotency_key')]));
                }
            }],
        ])->validate();

        if ($command->expectedLockVersion !== null && $command->expectedLockVersion < 0) {
            throw ValidationException::withMessages(['expected_lock_version' => __('app.workflow_errors.invalid_version')]);
        }
    }

    private function auditSnapshot(Order $order, ProcessThread $thread): array
    {
        return [
            'state' => $thread->state,
            'responsible_party' => $thread->responsible_party->value,
            'responsible_organization_id' => $thread->responsible_organization_id,
            'vendor_routing' => $thread->vendor_routing?->value,
            'eta' => $thread->eta?->toAtomString(),
            'lock_version' => $thread->lock_version,
            'completed_at' => $thread->completed_at?->toAtomString(),
            'order_routing' => $order->routing?->value,
            'order_closed_at' => $order->closed_at?->toAtomString(),
        ];
    }

    private function requestHash(ThreadWorkflowCommand $command): string
    {
        $data = $command->data;
        ksort($data);

        return hash('sha256', json_encode([
            'action' => $command->action,
            'actor_id' => $command->actor->id,
            'data' => $data,
            'expected_lock_version' => $command->expectedLockVersion,
        ], JSON_THROW_ON_ERROR));
    }
}
