<?php

namespace Tests\Feature;

use App\Enums\ItemRole;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\UserRole;
use App\Enums\WarrantyAction;
use App\Enums\WarrantyState;
use App\Models\Item;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\ThreadEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase6bWarrantyPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_menu_queue_and_multi_cycle_detail_have_constant_query_counts(): void
    {
        $ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'Polska']);
        $viewer = User::factory()->create(['organization_id' => $ak->id, 'role' => UserRole::AK_OPERATOR]);
        $order = Order::factory()->create(['order_no' => 'PERF-WARRANTY', 'source_order_id' => 'PERF-WARRANTY']);
        ProcessThread::factory()->create([
            'order_id' => $order->id, 'type' => ProcessThreadType::CUSTOM_REBUILD, 'type_instance' => 1,
            'state' => 'RECEIVED_AK', 'responsible_party' => ResponsibleParty::AUTOKLUBY,
            'responsible_organization_id' => $ak->id,
        ]);
        $this->createWarrantyRows($order, $viewer, $ak, 2, 1);
        $this->actingAs($viewer);

        $smallDashboard = $this->selectQueryCount(route('dashboard'));
        $smallQueue = $this->selectQueryCount(route('warranties.index'));
        $smallDetail = $this->selectQueryCount(route('orders.show', $order));

        $this->createWarrantyRows($order, $viewer, $ak, 98, 3);
        $largeDashboard = $this->selectQueryCount(route('dashboard'));
        $largeQueue = $this->selectQueryCount(route('warranties.index'));
        $largeDetail = $this->selectQueryCount(route('orders.show', $order));

        self::assertLessThanOrEqual($smallDashboard + 1, $largeDashboard, "Dashboard/menu queries grew from {$smallDashboard} to {$largeDashboard}.");
        self::assertLessThanOrEqual($smallQueue + 1, $largeQueue, "Warranty queue queries grew from {$smallQueue} to {$largeQueue}.");
        self::assertLessThanOrEqual($smallDetail + 1, $largeDetail, "Warranty detail queries grew from {$smallDetail} to {$largeDetail}.");
        self::assertLessThanOrEqual(25, $largeDashboard);
        self::assertLessThanOrEqual(16, $largeQueue);
        self::assertLessThanOrEqual(25, $largeDetail);
    }

    private function createWarrantyRows(Order $order, User $creator, Organization $ak, int $count, int $start): void
    {
        foreach (range($start, $start + $count - 1) as $instance) {
            $item = Item::factory()->create([
                'order_id' => $order->id,
                'role' => ItemRole::CUSTOMER_ITEM,
                'role_instance' => $instance,
                'current_location' => OrganizationCode::AUTOKLUBY,
            ]);
            $thread = ProcessThread::query()->create([
                'order_id' => $order->id,
                'item_id' => $item->id,
                'type' => ProcessThreadType::WARRANTY,
                'type_instance' => $instance,
                'state' => $instance <= 10 ? WarrantyState::SENT_TO_CUSTOMER : WarrantyState::RECEIVED_AK,
                'responsible_party' => ResponsibleParty::AUTOKLUBY,
                'responsible_organization_id' => $ak->id,
                'warranty_cycle' => 1,
                'created_by' => $creator->id,
                'creation_idempotency_key' => 'perf-create-'.$instance,
                'creation_request_hash' => hash('sha256', 'perf-'.$instance),
                'completed_at' => $instance <= 10 ? now() : null,
            ]);
            $item->update(['active_warranty_thread_id' => $instance <= 10 ? null : $thread->id]);
            ThreadEvent::query()->create([
                'process_thread_id' => $thread->id,
                'order_id' => $order->id,
                'action' => WarrantyAction::CREATE_CYCLE->value,
                'from_state' => null,
                'to_state' => WarrantyState::RECEIVED_AK->value,
                'actor_type' => OrganizationCode::AUTOKLUBY->value,
                'actor_user_id' => $creator->id,
                'actor_organization_id' => $ak->id,
                'responsible_organization_id' => $ak->id,
                'idempotency_key' => 'perf-event-'.$instance,
                'request_hash' => hash('sha256', 'perf-event-'.$instance),
            ]);
            if ($instance <= 10) {
                $active = ProcessThread::query()->create([
                    'order_id' => $order->id,
                    'item_id' => $item->id,
                    'type' => ProcessThreadType::WARRANTY,
                    'type_instance' => 1000 + $instance,
                    'state' => WarrantyState::RECEIVED_AK,
                    'responsible_party' => ResponsibleParty::AUTOKLUBY,
                    'responsible_organization_id' => $ak->id,
                    'warranty_cycle' => 2,
                    'created_by' => $creator->id,
                    'creation_idempotency_key' => 'perf-create-second-'.$instance,
                    'creation_request_hash' => hash('sha256', 'perf-second-'.$instance),
                ]);
                $item->update(['active_warranty_thread_id' => $active->id]);
                ThreadEvent::query()->create([
                    'process_thread_id' => $active->id, 'order_id' => $order->id,
                    'action' => WarrantyAction::CREATE_CYCLE->value, 'from_state' => null,
                    'to_state' => WarrantyState::RECEIVED_AK->value, 'actor_type' => OrganizationCode::AUTOKLUBY->value,
                    'actor_user_id' => $creator->id, 'actor_organization_id' => $ak->id,
                    'responsible_organization_id' => $ak->id, 'idempotency_key' => 'perf-event-second-'.$instance,
                    'request_hash' => hash('sha256', 'perf-event-second-'.$instance),
                ]);
            }
        }
    }

    private function selectQueryCount(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $count = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select'))
            ->count();
        DB::disableQueryLog();

        return $count;
    }
}
