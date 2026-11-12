<?php

namespace Tests\Feature;

use App\Application\Orders\InitialProcessThreadFactory;
use App\Application\Orders\StockAvailabilityDecision;
use App\Application\Orders\StockBranchDecisionService;
use App\Contracts\ResolvesStockAvailability;
use App\Enums\OrganizationCode;
use App\Enums\StockAvailabilityStatus;
use App\Enums\UserRole;
use App\Enums\WorkflowScenario;
use App\Models\Order;
use App\Models\OrderCustomer;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixtureStockAvailabilityResolver;
use Tests\TestCase;

class OrderDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $autokluby;

    private Organization $poland;

    private InitialProcessThreadFactory $threadFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autokluby = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $this->poland = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $this->threadFactory = app(InitialProcessThreadFactory::class);
    }

    public function test_autokluby_dashboard_and_detail_show_parallel_threads_and_items(): void
    {
        $user = User::factory()->create(['organization_id' => $this->autokluby->id, 'role' => UserRole::AK_OPERATOR]);
        $order = Order::factory()->stockRebuild()->create(['order_no' => 'TEST-1001', 'vehicle' => 'VW Passat']);
        OrderCustomer::factory()->for($order)->create(['email' => 'customer@example.test']);
        $this->threadFactory->createFor($order, WorkflowScenario::PENDING_STOCK_CHECK);
        app()->instance(ResolvesStockAvailability::class, new FixtureStockAvailabilityResolver(new StockAvailabilityDecision(
            StockAvailabilityStatus::AVAILABLE_IN_AK,
            'dashboard-fixture',
            CarbonImmutable::parse('2026-11-12 12:00:00'),
        )));
        app(StockBranchDecisionService::class)->decide($order, 'dashboard-stock-decision');

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('TEST-1001')
            ->assertSee('VW Passat')
            ->assertSee('Doplnění skladu');

        $this->actingAs($user)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('customer@example.test')
            ->assertSee('Čeká na vratný díl')
            ->assertSee('Fyzické kusy');
    }

    public function test_poland_sees_only_orders_with_a_relevant_thread_and_no_customer_pii(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->poland->id,
            'role' => UserRole::PL_OPERATOR,
            'preferred_locale' => 'pl',
        ]);
        $visible = Order::factory()->create(['order_no' => 'VISIBLE-PL']);
        $hidden = Order::factory()->create(['order_no' => 'HIDDEN-AK']);
        OrderCustomer::factory()->for($visible)->create(['email' => 'private@example.test']);
        $visibleThread = $this->threadFactory->createFor($visible, WorkflowScenario::CUSTOM_REBUILD)->sole();
        $visibleThread->update([
            'state' => 'SENT_TO_PL',
            'responsible_party' => 'POLAND',
            'responsible_organization_id' => $this->poland->id,
        ]);
        $this->threadFactory->createFor($hidden, WorkflowScenario::CUSTOM_REBUILD);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('VISIBLE-PL')
            ->assertDontSee('HIDDEN-AK');

        $this->actingAs($user)->get(route('orders.show', $visible))
            ->assertOk()
            ->assertDontSee('private@example.test');
        $this->actingAs($user)->get(route('orders.show', $hidden))->assertForbidden();
    }

    public function test_production_import_remains_disabled(): void
    {
        self::assertFalse(config('integrations.legacy_shop.enabled'));
        self::assertFalse(config('integrations.pohoda_bridge.enabled'));
    }

    public function test_poland_keeps_access_to_a_historically_assigned_order(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->poland->id,
            'role' => UserRole::PL_OPERATOR,
        ]);
        $order = Order::factory()->create(['order_no' => 'HISTORIC-PL']);
        $thread = $this->threadFactory->createFor($order, WorkflowScenario::CUSTOM_REBUILD)->sole();
        $thread->events()->create([
            'order_id' => $order->id,
            'action' => 'SEND_TO_POLAND',
            'from_state' => 'RECEIVED_AK',
            'to_state' => 'SENT_TO_PL',
            'actor_type' => 'AUTOKLUBY',
            'responsible_organization_id' => $this->poland->id,
        ]);
        $thread->update([
            'responsible_organization_id' => $this->autokluby->id,
        ]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('HISTORIC-PL');
        $this->actingAs($user)->get(route('orders.show', $order))->assertOk();
    }
}
