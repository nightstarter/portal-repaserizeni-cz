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
use App\Enums\UserRole;
use App\Exceptions\WorkflowConflictException;
use App\Models\Item;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\ThreadEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CustomRebuildWorkflow
{
    private const AK_ACTIONS = [
        CustomRebuildAction::CONFIRM_AK_RECEIPT,
        CustomRebuildAction::SEND_TO_POLAND,
        CustomRebuildAction::CONFIRM_AK_RETURN_RECEIPT,
        CustomRebuildAction::SEND_TO_CUSTOMER,
        CustomRebuildAction::MARK_READY_PICKUP,
        CustomRebuildAction::CONFIRM_CUSTOMER_PICKUP,
    ];

    private const PL_ACTIONS = [
        CustomRebuildAction::CONFIRM_PL_RECEIPT,
        CustomRebuildAction::MARK_REPAIRED_PL,
        CustomRebuildAction::SEND_TO_AUTOKLUBY,
    ];

    public function __construct(
        private readonly CustomRebuildStateMachine $stateMachine,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(Order $order, CustomRebuildCommand $command): ThreadEvent
    {
        Validator::make(['idempotency_key' => $command->idempotencyKey], [
            'idempotency_key' => ['required', 'string', 'max:128', function (string $attribute, mixed $value, \Closure $fail): void {
                if (trim((string) $value) === '') {
                    $fail('The idempotency key field is required.');
                }
            }],
        ])->validate();

        if ($command->expectedLockVersion !== null && $command->expectedLockVersion < 0) {
            throw ValidationException::withMessages(['expected_lock_version' => 'The expected lock version must be non-negative.']);
        }

        return DB::transaction(function () use ($order, $command): ThreadEvent {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $thread = ProcessThread::query()
                ->where('order_id', $lockedOrder->id)
                ->where('type', ProcessThreadType::CUSTOM_REBUILD)
                ->lockForUpdate()
                ->sole();

            $this->authorize($command);
            $requestHash = $this->requestHash($command);
            $existing = $thread->events()->where('idempotency_key', $command->idempotencyKey)->first();

            if ($existing !== null) {
                if ($existing->request_hash !== $requestHash) {
                    throw new WorkflowConflictException('The idempotency key was already used for a different command.');
                }

                return $existing;
            }

            $this->rejectStateInjection($command);

            if ($command->expectedLockVersion !== null && $command->expectedLockVersion !== $thread->lock_version) {
                throw new WorkflowConflictException('The process thread was changed by another request.');
            }

            $current = $thread->customRebuildState();
            $next = $command->action === CustomRebuildAction::ADMIN_OVERRIDE
                ? $this->overrideTarget($command)
                : $this->normalTarget($thread, $current, $command);

            $this->assertHandover($lockedOrder, $command->action);
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
                throw new WorkflowConflictException('The process thread was changed by another request.');
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

    private function authorize(CustomRebuildCommand $command): void
    {
        $actor = $command->actor;

        if (! $actor->active || ! $actor->organization->active || $actor->role === UserRole::READ_ONLY) {
            throw new AuthorizationException;
        }

        if ($command->action === CustomRebuildAction::ADMIN_OVERRIDE) {
            if ($actor->organization->code !== OrganizationCode::AUTOKLUBY || $actor->role !== UserRole::AK_ADMIN) {
                throw new AuthorizationException;
            }

            return;
        }

        if (in_array($command->action, self::AK_ACTIONS, true)) {
            $allowed = $actor->organization->code === OrganizationCode::AUTOKLUBY
                && in_array($actor->role, [UserRole::AK_ADMIN, UserRole::AK_OPERATOR], true);
        } else {
            $allowed = in_array($command->action, self::PL_ACTIONS, true)
                && $actor->organization->code === OrganizationCode::POLAND
                && in_array($actor->role, [UserRole::PL_MANAGER, UserRole::PL_OPERATOR], true);
        }

        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    private function normalTarget(
        ProcessThread $thread,
        CustomRebuildState $current,
        CustomRebuildCommand $command,
    ): CustomRebuildState {
        $entryAction = null;

        if ($current === CustomRebuildState::RECEIVED_AK) {
            $lastAction = $thread->events()->latest('id')->value('action');
            $entryAction = is_string($lastAction) ? CustomRebuildAction::tryFrom($lastAction) : null;
        }

        return $this->stateMachine->nextState($current, $command->action, $entryAction);
    }

    private function overrideTarget(CustomRebuildCommand $command): CustomRebuildState
    {
        $validated = Validator::make($command->data, [
            'target_state' => ['required', Rule::enum(CustomRebuildState::class)],
            'reason' => ['required', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail): void {
                if (trim((string) $value) === '') {
                    $fail('The reason field is required.');
                }
            }],
        ])->validate();

        return CustomRebuildState::from($validated['target_state']);
    }

    private function rejectStateInjection(CustomRebuildCommand $command): void
    {
        if ($command->action !== CustomRebuildAction::ADMIN_OVERRIDE
            && Arr::hasAny($command->data, ['state', 'to_state', 'target_state'])) {
            throw ValidationException::withMessages(['state' => 'A target state cannot be supplied for this action.']);
        }
    }

    private function assertHandover(Order $order, CustomRebuildAction $action): void
    {
        if ($action === CustomRebuildAction::SEND_TO_CUSTOMER && $order->customer_handover !== 'GLS') {
            throw new WorkflowConflictException('SEND_TO_CUSTOMER requires GLS handover.');
        }

        if ($action === CustomRebuildAction::MARK_READY_PICKUP && $order->customer_handover !== 'PERSONAL_PICKUP') {
            throw new WorkflowConflictException('MARK_READY_PICKUP requires personal pickup.');
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
