<?php

namespace Tests\Unit;

use App\Application\Orders\InitialProcessThreadFactory;
use App\Enums\ItemRole;
use App\Enums\OrderType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\WorkflowScenario;
use App\Models\Order;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitialProcessThreadFactoryTest extends TestCase
{
    use RefreshDatabase;

    private InitialProcessThreadFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $this->factory = app(InitialProcessThreadFactory::class);
    }

    public function test_custom_rebuild_starts_as_one_customer_waiting_thread(): void
    {
        $order = Order::factory()->create(['order_type' => OrderType::CUSTOM_REBUILD]);

        $threads = $this->factory->createFor($order, WorkflowScenario::CUSTOM_REBUILD);

        self::assertCount(1, $threads);
        self::assertSame(ProcessThreadType::CUSTOM_REBUILD, $threads->first()->type);
        self::assertSame('WAITING_CUSTOMER_ITEM', $threads->first()->state);
        self::assertSame(ResponsibleParty::AUTOKLUBY, $threads->first()->responsible_party);
        self::assertSame($this->factoryOrganizationId(OrganizationCode::AUTOKLUBY), $threads->first()->responsible_organization_id);
        self::assertSame(1, $order->items()->count());
        $item = $order->items()->sole();
        self::assertSame(ItemRole::CUSTOMER_ITEM, $item->role);
        self::assertSame('CUSTOMER', $item->current_location);
        self::assertSame(48, strlen($item->qr_token));
        $this->assertDatabaseHas('thread_events', [
            'process_thread_id' => $threads->first()->id,
            'action' => 'THREAD_CREATED',
            'to_state' => 'WAITING_CUSTOMER_ITEM',
        ]);
    }

    public function test_stock_order_in_decín_gets_three_independent_threads(): void
    {
        $order = Order::factory()->stockRebuild()->create();

        $threads = $this->factory->createFor($order, WorkflowScenario::STOCK_DECIN);

        self::assertEqualsCanonicalizing([
            ProcessThreadType::STOCK_REQUEST,
            ProcessThreadType::STOCK_REPLENISHMENT,
            ProcessThreadType::CORE_RETURN,
        ], $threads->pluck('type')->all());
        self::assertSame(3, $order->processThreads()->count());
        self::assertSame(3, $order->threadEvents()->count());
    }

    public function test_stock_order_waits_for_pohoda_without_polish_thread(): void
    {
        $order = Order::factory()->stockRebuild()->create();

        $threads = $this->factory->createFor($order, WorkflowScenario::PENDING_STOCK_CHECK);

        self::assertCount(1, $threads);
        self::assertSame(ProcessThreadType::STOCK_REQUEST, $threads->sole()->type);
        self::assertSame('PENDING_STOCK_CHECK', $threads->sole()->state);
        self::assertSame(ResponsibleParty::AUTOKLUBY, $threads->sole()->responsible_party);
        self::assertFalse($order->processThreads()->where('responsible_party', ResponsibleParty::POLAND)->exists());
    }

    public function test_creating_initial_threads_is_idempotent(): void
    {
        $order = Order::factory()->stockRebuild()->create();

        $this->factory->createFor($order, WorkflowScenario::STOCK_POLAND);
        $this->factory->createFor($order, WorkflowScenario::STOCK_POLAND);

        self::assertSame(2, $order->processThreads()->count());
        self::assertSame(2, $order->threadEvents()->count());
    }

    public function test_creating_custom_rebuild_domain_is_idempotent(): void
    {
        $order = Order::factory()->create();

        $this->factory->createFor($order, WorkflowScenario::CUSTOM_REBUILD);
        $this->factory->createFor($order, WorkflowScenario::CUSTOM_REBUILD);

        self::assertSame(1, $order->processThreads()->count());
        self::assertSame(1, $order->items()->where('role', ItemRole::CUSTOMER_ITEM)->count());
        self::assertSame(1, $order->threadEvents()->where('action', 'THREAD_CREATED')->count());
    }

    private function factoryOrganizationId(OrganizationCode $code): int
    {
        return Organization::query()->where('code', $code)->valueOrFail('id');
    }
}
