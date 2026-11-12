<?php

namespace App\Application\Orders;

use App\Enums\ItemRole;
use App\Enums\OrderType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\StockRebuildAction;
use App\Enums\StockRebuildState;
use App\Exceptions\WorkflowConflictException;
use App\Models\Item;
use App\Models\Order;
use App\Models\ProcessThread;
use App\Models\ThreadEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StockRebuildWorkflow
{
    public function __construct(
        private readonly StockRebuildActionGuard $guard,
        private readonly ThreadWorkflowEngine $engine,
    ) {}

    public function execute(Order $order, ProcessThreadType $threadType, StockRebuildCommand $command): ThreadEvent
    {
        $this->guard->assertThreadType($threadType);
        $generic = new ThreadWorkflowCommand(
            $command->action->value,
            $command->actor,
            $command->idempotencyKey,
            $command->data,
            $command->expectedLockVersion,
        );

        return $this->engine->execute(
            $order,
            $threadType,
            $generic,
            function (Order $lockedOrder) use ($command): void {
                if ($lockedOrder->order_type !== OrderType::STOCK_REBUILD) {
                    throw new WorkflowConflictException(__('app.workflow_errors.invalid_stock_order'));
                }

                $this->guard->assertActorMayPerform($command->actor, $command->action);
                if ($command->action !== StockRebuildAction::ADMIN_OVERRIDE
                    && Arr::hasAny($command->data, ['state', 'to_state', 'target_state'])) {
                    throw ValidationException::withMessages(['state' => __('app.workflow_errors.target_state_forbidden')]);
                }
            },
            function (Order $lockedOrder, ProcessThread $thread) use ($command): ThreadTransition {
                $next = $command->action === StockRebuildAction::ADMIN_OVERRIDE
                    ? $this->overrideTarget($thread, $command)
                    : $this->guard->nextState($lockedOrder, $thread, $command->action);
                $eta = $this->etaFor($command);
                $metadata = $eta === null ? [] : ['eta' => $eta->toAtomString()];
                $this->movePhysicalItem($lockedOrder, $thread, $command->action);
                [$party, $organization] = $this->responsibilityFor($next);

                return new ThreadTransition(
                    $next->value,
                    $party,
                    $organization,
                    $next->isTerminalFor($thread->type),
                    $metadata,
                    $command->action === StockRebuildAction::ADMIN_OVERRIDE
                        ? trim((string) $command->data['reason'])
                        : null,
                    keepEta: $command->action !== StockRebuildAction::SET_PL_STOCK_ETA,
                    eta: $eta,
                );
            },
            $command->action === StockRebuildAction::ADMIN_OVERRIDE
                ? 'STOCK_REBUILD_ADMIN_OVERRIDE'
                : 'STOCK_REBUILD_ACTION',
        );
    }

    private function overrideTarget(ProcessThread $thread, StockRebuildCommand $command): StockRebuildState
    {
        $allowedStates = match ($thread->type) {
            ProcessThreadType::STOCK_REQUEST => [
                StockRebuildState::PENDING_STOCK_CHECK,
                StockRebuildState::READY_AK_DISPATCH,
                StockRebuildState::WAITING_PL_STOCK,
                StockRebuildState::PL_SECURING_STOCK,
                StockRebuildState::PL_STOCK_AVAILABLE,
                StockRebuildState::SENT_TO_AK,
                StockRebuildState::RECEIVED_AK,
                StockRebuildState::SENT_CUSTOMER,
                StockRebuildState::READY_PICKUP,
                StockRebuildState::PICKED_UP,
            ],
            ProcessThreadType::STOCK_REPLENISHMENT => [
                StockRebuildState::WAITING_PL_STOCK,
                StockRebuildState::PL_SECURING_STOCK,
                StockRebuildState::PL_STOCK_AVAILABLE,
                StockRebuildState::SENT_TO_AK,
                StockRebuildState::RECEIVED_AK,
            ],
            default => [],
        };

        $validated = Validator::make($command->data, [
            'target_state' => ['required', Rule::in(array_map(
                static fn (StockRebuildState $state): string => $state->value,
                $allowedStates,
            ))],
            'reason' => ['required', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail): void {
                if (trim((string) $value) === '') {
                    $fail(__('validation.required', ['attribute' => __('validation.attributes.reason')]));
                }
            }],
        ])->validate();

        return StockRebuildState::from($validated['target_state']);
    }

    private function etaFor(StockRebuildCommand $command): ?CarbonImmutable
    {
        if ($command->action !== StockRebuildAction::SET_PL_STOCK_ETA) {
            return null;
        }

        $validated = Validator::make($command->data, ['eta' => ['required', 'date', 'after:now']])->validate();

        return CarbonImmutable::parse($validated['eta']);
    }

    private function movePhysicalItem(Order $order, ProcessThread $thread, StockRebuildAction $action): void
    {
        if (! in_array($action, [
            StockRebuildAction::CONFIRM_AK_STOCK_RECEIPT,
            StockRebuildAction::SEND_TO_CUSTOMER,
            StockRebuildAction::CONFIRM_CUSTOMER_PICKUP,
        ], true)) {
            return;
        }

        $role = $thread->type === ProcessThreadType::STOCK_REPLENISHMENT
            ? ItemRole::STOCK_REPLACEMENT
            : ItemRole::STOCK_ITEM;
        $location = in_array($action, [StockRebuildAction::SEND_TO_CUSTOMER, StockRebuildAction::CONFIRM_CUSTOMER_PICKUP], true)
            ? ResponsibleParty::CUSTOMER->value
            : OrganizationCode::AUTOKLUBY->value;

        Item::query()
            ->where('order_id', $order->id)
            ->where('role', $role)
            ->lockForUpdate()
            ->sole()
            ->update(['current_location' => $location]);
    }

    /** @return array{0: ResponsibleParty, 1: ?OrganizationCode} */
    private function responsibilityFor(StockRebuildState $state): array
    {
        return match ($state) {
            StockRebuildState::WAITING_PL_STOCK,
            StockRebuildState::PL_SECURING_STOCK,
            StockRebuildState::PL_STOCK_AVAILABLE => [ResponsibleParty::POLAND, OrganizationCode::POLAND],
            StockRebuildState::PENDING_STOCK_CHECK,
            StockRebuildState::READY_AK_DISPATCH,
            StockRebuildState::SENT_TO_AK,
            StockRebuildState::RECEIVED_AK => [ResponsibleParty::AUTOKLUBY, OrganizationCode::AUTOKLUBY],
            StockRebuildState::SENT_CUSTOMER,
            StockRebuildState::READY_PICKUP,
            StockRebuildState::PICKED_UP,
            StockRebuildState::WAITING_CORE => [ResponsibleParty::CUSTOMER, null],
        };
    }
}
