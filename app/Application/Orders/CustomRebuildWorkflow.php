<?php

namespace App\Application\Orders;

use App\Application\Audit\AuditLogger;
use App\Enums\CustomRebuildAction;
use App\Enums\CustomRebuildState;
use App\Enums\ItemInspectionResult;
use App\Enums\ItemRole;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\RebuildFaultCode;
use App\Enums\ResponsibleParty;
use App\Enums\RoutingCode;
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

final class CustomRebuildWorkflow
{
    public function __construct(
        private readonly CustomRebuildActionGuard $actionGuard,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(Order $order, CustomRebuildCommand $command): ThreadEvent
    {
        Validator::make(['idempotency_key' => $command->idempotencyKey], [
            'idempotency_key' => ['required', 'string', 'max:128', function (string $attribute, mixed $value, \Closure $fail): void {
                if (trim((string) $value) === '') {
                    $fail(__('validation.required', ['attribute' => __('validation.attributes.idempotency_key')]));
                }
            }],
        ])->validate();

        if ($command->expectedLockVersion !== null && $command->expectedLockVersion < 0) {
            throw ValidationException::withMessages(['expected_lock_version' => __('app.workflow_errors.invalid_version')]);
        }

        return DB::transaction(function () use ($order, $command): ThreadEvent {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $thread = ProcessThread::query()
                ->where('order_id', $lockedOrder->id)
                ->where('type', ProcessThreadType::CUSTOM_REBUILD)
                ->lockForUpdate()
                ->sole();

            $this->actionGuard->assertActorMayPerform($command->actor, $command->action);
            $requestHash = $this->requestHash($command);
            $existing = $thread->events()->where('idempotency_key', $command->idempotencyKey)->first();

            if ($existing !== null) {
                if ($existing->request_hash !== $requestHash) {
                    throw new WorkflowConflictException(__('app.workflow_errors.idempotency_reused'));
                }

                return $existing;
            }

            $this->rejectStateInjection($command);

            if ($command->expectedLockVersion !== null && $command->expectedLockVersion !== $thread->lock_version) {
                throw new WorkflowConflictException(__('app.workflow_errors.version_conflict'));
            }

            $current = $thread->customRebuildState();
            $next = $command->action === CustomRebuildAction::ADMIN_OVERRIDE
                ? $this->overrideTarget($command)
                : $this->actionGuard->nextState($lockedOrder, $thread, $command->action);

            $oldValues = $this->auditSnapshot($lockedOrder, $thread);
            $receiptMetadata = $command->action === CustomRebuildAction::CONFIRM_AK_RECEIPT
                ? $this->recordReceipt($lockedOrder, $thread, $command->data)
                : [];

            [$party, $organization] = $this->responsibilityFor($next);
            $now = now();
            $updated = ProcessThread::query()
                ->whereKey($thread->id)
                ->where('lock_version', $thread->lock_version)
                ->update([
                    'state' => $next->value,
                    'responsible_party' => $party->value,
                    'responsible_organization_id' => $organization?->id,
                    'completed_at' => $next->isTerminal() ? $now : null,
                    'lock_version' => $thread->lock_version + 1,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                throw new WorkflowConflictException(__('app.workflow_errors.version_conflict'));
            }

            $lockedOrder->forceFill(['closed_at' => $next->isTerminal() ? $now : null])->save();
            $thread->refresh();

            $reason = $command->action === CustomRebuildAction::ADMIN_OVERRIDE
                ? trim((string) $command->data['reason'])
                : null;
            $event = $thread->events()->create([
                'order_id' => $lockedOrder->id,
                'action' => $command->action->value,
                'idempotency_key' => $command->idempotencyKey,
                'request_hash' => $requestHash,
                'from_state' => $current->value,
                'to_state' => $next->value,
                'actor_type' => $command->actor->organization->code->value,
                'actor_user_id' => $command->actor->id,
                'actor_organization_id' => $command->actor->organization_id,
                'responsible_organization_id' => $organization?->id,
                'reason' => $reason,
                'metadata' => $receiptMetadata,
            ]);

            $this->auditLogger->record(
                $command->action === CustomRebuildAction::ADMIN_OVERRIDE
                    ? 'CUSTOM_REBUILD_ADMIN_OVERRIDE'
                    : 'CUSTOM_REBUILD_ACTION',
                $thread,
                $oldValues,
                $this->auditSnapshot($lockedOrder->fresh(), $thread->fresh()) + [
                    'thread_event_id' => $event->id,
                    'receipt' => $receiptMetadata,
                ],
                $reason,
                $command->actor,
            );

            return $event;
        }, 3);
    }

    private function overrideTarget(CustomRebuildCommand $command): CustomRebuildState
    {
        $validated = Validator::make($command->data, [
            'target_state' => ['required', Rule::enum(CustomRebuildState::class)],
            'reason' => ['required', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail): void {
                if (trim((string) $value) === '') {
                    $fail(__('validation.required', ['attribute' => __('validation.attributes.reason')]));
                }
            }],
        ])->validate();

        return CustomRebuildState::from($validated['target_state']);
    }

    private function rejectStateInjection(CustomRebuildCommand $command): void
    {
        if ($command->action !== CustomRebuildAction::ADMIN_OVERRIDE
            && Arr::hasAny($command->data, ['state', 'to_state', 'target_state'])) {
            throw ValidationException::withMessages(['state' => __('app.workflow_errors.target_state_forbidden')]);
        }
    }

    private function recordReceipt(Order $order, ProcessThread $thread, array $data): array
    {
        $validated = Validator::make($data, [
            'inspection_result' => ['required', Rule::enum(ItemInspectionResult::class)],
            'condition_description' => ['nullable', 'string', 'max:5000'],
            'routing' => ['required', Rule::enum(RoutingCode::class)],
            'fault_code' => ['required', Rule::enum(RebuildFaultCode::class)],
            'fault_note' => ['nullable', 'string', 'max:5000'],
            'axial_rods' => ['required', 'boolean'],
        ])->validate();

        $item = Item::query()
            ->where('order_id', $order->id)
            ->where('role', ItemRole::CUSTOMER_ITEM)
            ->lockForUpdate()
            ->sole();
        $attributes = [
            'condition_description' => $validated['condition_description'] ?? null,
            'fault_code' => $validated['fault_code'],
            'fault_note' => $validated['fault_note'] ?? null,
            'axial_rods' => (bool) $validated['axial_rods'],
        ];

        $item->update([
            'current_location' => OrganizationCode::AUTOKLUBY->value,
            'inspection_result' => $validated['inspection_result'],
            'technical_attributes' => $attributes,
        ]);
        $order->update(['routing' => $validated['routing']]);
        $thread->update(['vendor_routing' => $validated['routing']]);

        return $attributes + [
            'inspection_result' => $validated['inspection_result'],
            'routing' => $validated['routing'],
            'axial_rods_surcharge_czk' => $item->fresh()->axialRodsSurchargeCzk(),
        ];
    }

    /** @return array{0: ResponsibleParty, 1: ?Organization} */
    private function responsibilityFor(CustomRebuildState $state): array
    {
        $code = match ($state) {
            CustomRebuildState::SENT_TO_PL,
            CustomRebuildState::RECEIVED_PL_REPAIR,
            CustomRebuildState::REPAIRED_PL => OrganizationCode::POLAND,
            CustomRebuildState::WAITING_CUSTOMER_ITEM,
            CustomRebuildState::RECEIVED_AK,
            CustomRebuildState::SENT_TO_AK => OrganizationCode::AUTOKLUBY,
            CustomRebuildState::SENT_CUSTOMER,
            CustomRebuildState::READY_PICKUP,
            CustomRebuildState::PICKED_UP => null,
        };

        if ($code === null) {
            return [ResponsibleParty::CUSTOMER, null];
        }

        return [
            $code === OrganizationCode::AUTOKLUBY ? ResponsibleParty::AUTOKLUBY : ResponsibleParty::POLAND,
            Organization::query()->where('code', $code)->sole(),
        ];
    }

    private function auditSnapshot(Order $order, ProcessThread $thread): array
    {
        return [
            'state' => $thread->state,
            'responsible_party' => $thread->responsible_party->value,
            'responsible_organization_id' => $thread->responsible_organization_id,
            'vendor_routing' => $thread->vendor_routing?->value,
            'lock_version' => $thread->lock_version,
            'completed_at' => $thread->completed_at?->toAtomString(),
            'order_routing' => $order->routing?->value,
            'order_closed_at' => $order->closed_at?->toAtomString(),
        ];
    }

    private function requestHash(CustomRebuildCommand $command): string
    {
        $data = $command->data;
        ksort($data);

        return hash('sha256', json_encode([
            'action' => $command->action->value,
            'actor_id' => $command->actor->id,
            'data' => $data,
            'expected_lock_version' => $command->expectedLockVersion,
        ], JSON_THROW_ON_ERROR));
    }
}
