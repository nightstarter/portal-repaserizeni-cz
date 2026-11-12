<?php

namespace App\Application\Orders;

use App\Contracts\CreatesInitialProcessThreads;
use App\Enums\ItemRole;
use App\Enums\OrderType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\WorkflowScenario;
use App\Models\Item;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class InitialProcessThreadFactory implements CreatesInitialProcessThreads
{
    /**
     * @return Collection<int, ProcessThread>
     */
    public function createFor(Order $order, WorkflowScenario $scenario): Collection
    {
        $this->assertScenarioMatchesOrder($order, $scenario);

        return DB::transaction(function () use ($order, $scenario): Collection {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $organizations = Organization::query()
                ->whereIn('code', [OrganizationCode::AUTOKLUBY, OrganizationCode::POLAND])
                ->get()
                ->keyBy(fn (Organization $organization): string => $organization->code->value);

            $threads = new Collection;

            foreach ($this->definitions($scenario) as $definition) {
                $organizationCode = $definition['organization'];
                $organization = $organizationCode === null
                    ? null
                    : $organizations->get($organizationCode->value);

                if ($organizationCode !== null && $organization === null) {
                    throw new DomainException("Missing organization {$organizationCode->value}.");
                }

                $thread = ProcessThread::query()->firstOrCreate(
                    [
                        'order_id' => $lockedOrder->id,
                        'type' => $definition['type'],
                        'warranty_cycle' => null,
                    ],
                    [
                        'state' => $definition['state'],
                        'responsible_party' => $definition['party'],
                        'responsible_organization_id' => $organization?->id,
                        'vendor_routing' => $lockedOrder->routing,
                    ],
                );

                if ($thread->wasRecentlyCreated) {
                    $thread->events()->create([
                        'order_id' => $lockedOrder->id,
                        'action' => 'THREAD_CREATED',
                        'to_state' => $thread->state,
                        'actor_type' => ResponsibleParty::SYSTEM->value,
                        'responsible_organization_id' => $thread->responsible_organization_id,
                        'metadata' => ['scenario' => $scenario->value],
                    ]);
                }

                $threads->push($thread);
            }

            if ($scenario === WorkflowScenario::CUSTOM_REBUILD) {
                Item::query()->firstOrCreate(
                    ['order_id' => $lockedOrder->id, 'role' => ItemRole::CUSTOMER_ITEM],
                    ['current_location' => ResponsibleParty::CUSTOMER->value],
                );
            }

            return $threads;
        });
    }

    /**
     * @return array<int, array{type: ProcessThreadType, state: string, party: ResponsibleParty, organization: ?OrganizationCode}>
     */
    private function definitions(WorkflowScenario $scenario): array
    {
        return match ($scenario) {
            WorkflowScenario::CUSTOM_REBUILD => [
                $this->definition(ProcessThreadType::CUSTOM_REBUILD, 'WAITING_CUSTOMER_ITEM', ResponsibleParty::AUTOKLUBY, OrganizationCode::AUTOKLUBY),
            ],
            WorkflowScenario::PENDING_STOCK_CHECK => [
                $this->definition(ProcessThreadType::STOCK_REQUEST, 'PENDING_STOCK_CHECK', ResponsibleParty::AUTOKLUBY, OrganizationCode::AUTOKLUBY),
            ],
            WorkflowScenario::STOCK_DECIN => [
                $this->definition(ProcessThreadType::STOCK_REQUEST, 'READY_AK_DISPATCH', ResponsibleParty::AUTOKLUBY, OrganizationCode::AUTOKLUBY),
                $this->definition(ProcessThreadType::STOCK_REPLENISHMENT, 'WAITING_PL_STOCK', ResponsibleParty::POLAND, OrganizationCode::POLAND),
                $this->definition(ProcessThreadType::CORE_RETURN, 'WAITING_CORE', ResponsibleParty::CUSTOMER),
            ],
            WorkflowScenario::STOCK_POLAND => [
                $this->definition(ProcessThreadType::STOCK_REQUEST, 'WAITING_PL_STOCK', ResponsibleParty::POLAND, OrganizationCode::POLAND),
                $this->definition(ProcessThreadType::CORE_RETURN, 'WAITING_CORE', ResponsibleParty::CUSTOMER),
            ],
            WorkflowScenario::BUYOUT => [
                $this->definition(ProcessThreadType::BUYOUT, 'WAITING_AK_BUYOUT_REVIEW', ResponsibleParty::AUTOKLUBY, OrganizationCode::AUTOKLUBY),
            ],
        };
    }

    /**
     * @return array{type: ProcessThreadType, state: string, party: ResponsibleParty, organization: ?OrganizationCode}
     */
    private function definition(
        ProcessThreadType $type,
        string $state,
        ResponsibleParty $party,
        ?OrganizationCode $organization = null,
    ): array {
        return compact('type', 'state', 'party', 'organization');
    }

    private function assertScenarioMatchesOrder(Order $order, WorkflowScenario $scenario): void
    {
        $expected = match ($scenario) {
            WorkflowScenario::CUSTOM_REBUILD => OrderType::CUSTOM_REBUILD,
            WorkflowScenario::PENDING_STOCK_CHECK, WorkflowScenario::STOCK_DECIN, WorkflowScenario::STOCK_POLAND => OrderType::STOCK_REBUILD,
            WorkflowScenario::BUYOUT => OrderType::BUYOUT,
        };

        if ($order->order_type !== $expected) {
            throw new DomainException("Scenario {$scenario->value} does not match order type {$order->order_type->value}.");
        }
    }
}
