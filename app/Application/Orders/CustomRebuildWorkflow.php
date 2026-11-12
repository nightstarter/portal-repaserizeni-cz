<?php

namespace App\Application\Orders;

use App\Enums\CustomRebuildAction;
use App\Enums\CustomRebuildState;
use App\Enums\ItemInspectionResult;
use App\Enums\ItemRole;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\RebuildFaultCode;
use App\Enums\ResponsibleParty;
use App\Enums\RoutingCode;
use App\Models\Item;
use App\Models\Order;
use App\Models\ProcessThread;
use App\Models\ThreadEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CustomRebuildWorkflow
{
    public function __construct(
        private readonly CustomRebuildActionGuard $actionGuard,
        private readonly ThreadWorkflowEngine $engine,
    ) {}

    public function execute(Order $order, CustomRebuildCommand $command): ThreadEvent
    {
        $generic = new ThreadWorkflowCommand(
            $command->action->value,
            $command->actor,
            $command->idempotencyKey,
            $command->data,
            $command->expectedLockVersion,
        );

        return $this->engine->execute(
            $order,
            ProcessThreadType::CUSTOM_REBUILD,
            $generic,
            function () use ($command): void {
                $this->actionGuard->assertActorMayPerform($command->actor, $command->action);
                $this->rejectStateInjection($command);
            },
            function (Order $lockedOrder, ProcessThread $thread) use ($command): ThreadTransition {
                $next = $command->action === CustomRebuildAction::ADMIN_OVERRIDE
                    ? $this->overrideTarget($command)
                    : $this->actionGuard->nextState($lockedOrder, $thread, $command->action);
                $metadata = $command->action === CustomRebuildAction::CONFIRM_AK_RECEIPT
                    ? $this->recordReceipt($lockedOrder, $thread, $command->data)
                    : [];
                [$party, $organization] = $this->responsibilityFor($next);

                return new ThreadTransition(
                    $next->value,
                    $party,
                    $organization,
                    $next->isTerminal(),
                    $metadata,
                    $command->action === CustomRebuildAction::ADMIN_OVERRIDE
                        ? trim((string) $command->data['reason'])
                        : null,
                    keepEta: true,
                );
            },
            $command->action === CustomRebuildAction::ADMIN_OVERRIDE
                ? 'CUSTOM_REBUILD_ADMIN_OVERRIDE'
                : 'CUSTOM_REBUILD_ACTION',
        );
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

    /** @return array{0: ResponsibleParty, 1: ?OrganizationCode} */
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

        return [$code === OrganizationCode::AUTOKLUBY ? ResponsibleParty::AUTOKLUBY : ResponsibleParty::POLAND, $code];
    }
}
