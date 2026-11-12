<?php

namespace Tests\Feature;

use App\Application\Orders\InitialProcessThreadFactory;
use App\Application\Orders\StockAvailabilityDecision;
use App\Application\Orders\StockBranchDecisionService;
use App\Application\Orders\StockRebuildCommand;
use App\Application\Orders\StockRebuildWorkflow;
use App\Contracts\ResolvesStockAvailability;
use App\Enums\ItemRole;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\StockAvailabilityStatus;
use App\Enums\StockRebuildAction;
use App\Enums\StockRebuildState;
use App\Enums\UserRole;
use App\Enums\WorkflowScenario;
use App\Exceptions\WorkflowConflictException;
use App\Models\Item;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\FixtureStockAvailabilityResolver;
use Tests\TestCase;

class StockRebuildWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $autokluby;

    private Organization $poland;

    private User $akOperator;

    private User $akAdmin;

    private User $plOperator;

    private InitialProcessThreadFactory $initialFactory;

    private StockRebuildWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autokluby = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $this->poland = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $this->akOperator = User::factory()->create(['organization_id' => $this->autokluby->id, 'role' => UserRole::AK_OPERATOR]);
        $this->akAdmin = User::factory()->create(['organization_id' => $this->autokluby->id, 'role' => UserRole::AK_ADMIN]);
        $this->plOperator = User::factory()->create(['organization_id' => $this->poland->id, 'role' => UserRole::PL_OPERATOR]);
        $this->initialFactory = app(InitialProcessThreadFactory::class);
        $this->workflow = app(StockRebuildWorkflow::class);
    }

    public function test_missing_and_stale_snapshot_leave_only_pending_request(): void
    {
        foreach ([StockAvailabilityStatus::UNKNOWN, StockAvailabilityStatus::STALE] as $status) {
            $order = $this->newStockOrder();

            self::assertNull($this->decisionService($status)->decide($order, 'inconclusive-'.$status->value));
            self::assertSame(1, $order->processThreads()->count());
            self::assertSame(0, $order->items()->count());
            self::assertSame(StockRebuildState::PENDING_STOCK_CHECK->value, $order->processThreads()->sole()->state);
            self::assertFalse(Order::query()->visibleTo($this->plOperator)->whereKey($order)->exists());
        }
    }

    public function test_available_in_decín_atomically_creates_exactly_three_threads_and_items(): void
    {
        $order = $this->newStockOrder();

        $event = $this->decisionService(StockAvailabilityStatus::AVAILABLE_IN_AK)->decide($order, 'available-ak');

        self::assertSame(StockRebuildState::READY_AK_DISPATCH->value, $event?->to_state);
        self::assertEqualsCanonicalizing([
            ProcessThreadType::STOCK_REQUEST,
            ProcessThreadType::STOCK_REPLENISHMENT,
            ProcessThreadType::CORE_RETURN,
        ], $order->processThreads()->pluck('type')->all());
        self::assertEqualsCanonicalizing([
            ItemRole::STOCK_ITEM,
            ItemRole::STOCK_REPLACEMENT,
            ItemRole::CORE_RETURN,
        ], $order->items()->pluck('role')->all());
        self::assertSame(3, $order->processThreads()->count());
        self::assertSame(3, $order->items()->count());
        self::assertSame(3, $order->items()->pluck('qr_token')->unique()->count());
    }

    public function test_not_available_in_decín_creates_exactly_two_threads_and_items(): void
    {
        $order = $this->newStockOrder();

        $this->decisionService(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK)->decide($order, 'not-available-ak');

        self::assertEqualsCanonicalizing([
            ProcessThreadType::STOCK_REQUEST,
            ProcessThreadType::CORE_RETURN,
        ], $order->processThreads()->pluck('type')->all());
        self::assertEqualsCanonicalizing([ItemRole::STOCK_ITEM, ItemRole::CORE_RETURN], $order->items()->pluck('role')->all());
        self::assertFalse($order->processThreads()->where('type', ProcessThreadType::STOCK_REPLENISHMENT)->exists());
    }

    public function test_repeated_and_concurrent_equivalent_decisions_create_no_duplicates(): void
    {
        $order = $this->newStockOrder();
        $service = $this->decisionService(StockAvailabilityStatus::AVAILABLE_IN_AK);

        $first = $service->decide($order, 'decision-request-a');
        $second = $service->decide($order, 'decision-request-b');

        self::assertSame($first?->id, $second?->id);
        self::assertSame(3, $order->processThreads()->count());
        self::assertSame(3, $order->items()->count());
        self::assertSame(1, $order->threadEvents()->where('action', StockBranchDecisionService::ACTION)->count());
        self::assertSame(1, $order->auditLogs()->where('action', 'STOCK_AVAILABILITY_DECIDED')->count());
    }

    public function test_conflicting_second_decision_is_rejected_and_audited(): void
    {
        $order = $this->newStockOrder();
        $this->decisionService(StockAvailabilityStatus::AVAILABLE_IN_AK)->decide($order, 'first-decision');

        $this->expectException(WorkflowConflictException::class);
        try {
            $this->decisionService(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK)->decide($order, 'conflicting-decision');
        } finally {
            self::assertSame(3, $order->processThreads()->count());
            self::assertSame(3, $order->items()->count());
            self::assertSame(1, $order->auditLogs()->where('action', 'STOCK_AVAILABILITY_CONFLICT')->count());
        }
    }

    public function test_failure_mid_materialization_rolls_everything_back(): void
    {
        $order = $this->newStockOrder();
        $created = 0;
        Event::listen('eloquent.creating: '.Item::class, static function () use (&$created): void {
            $created++;
            if ($created === 2) {
                throw new RuntimeException('item creation failed');
            }
        });

        try {
            $this->decisionService(StockAvailabilityStatus::AVAILABLE_IN_AK)->decide($order, 'rollback-decision');
            self::fail('Expected materialization failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('item creation failed', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.Item::class);
        }

        self::assertSame(1, $order->processThreads()->count());
        self::assertSame(0, $order->items()->count());
        self::assertSame(StockRebuildState::PENDING_STOCK_CHECK->value, $order->processThreads()->sole()->state);
        self::assertSame(1, $order->threadEvents()->count());
        self::assertSame(0, $order->auditLogs()->count());
    }

    public function test_poland_can_set_and_change_required_eta_then_confirm_availability(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK);

        try {
            $this->execute($order, ProcessThreadType::STOCK_REQUEST, $this->plOperator, StockRebuildAction::SET_PL_STOCK_ETA, 'missing-eta');
            self::fail('Expected ETA validation failure.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('eta', $exception->errors());
        }

        $this->execute($order, ProcessThreadType::STOCK_REQUEST, $this->plOperator, StockRebuildAction::SET_PL_STOCK_ETA, 'eta-one', ['eta' => '2026-12-01 10:00:00']);
        $thread = $this->thread($order, ProcessThreadType::STOCK_REQUEST);
        self::assertSame(StockRebuildState::PL_SECURING_STOCK->value, $thread->state);
        self::assertSame('2026-12-01 10:00:00', $thread->eta?->format('Y-m-d H:i:s'));

        $this->execute($order, ProcessThreadType::STOCK_REQUEST, $this->plOperator, StockRebuildAction::SET_PL_STOCK_ETA, 'eta-two', ['eta' => '2026-12-08 12:30:00']);
        self::assertSame('2026-12-08 12:30:00', $thread->fresh()->eta?->format('Y-m-d H:i:s'));

        $this->execute($order, ProcessThreadType::STOCK_REQUEST, $this->plOperator, StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE, 'stock-available');
        self::assertSame(StockRebuildState::PL_STOCK_AVAILABLE->value, $thread->fresh()->state);
    }

    public function test_polish_dispatch_and_autokluby_receipt_are_role_protected(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK);
        $type = ProcessThreadType::STOCK_REQUEST;
        $this->execute($order, $type, $this->plOperator, StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE, 'available');

        $this->expectException(AuthorizationException::class);
        try {
            $this->execute($order, $type, $this->akOperator, StockRebuildAction::SEND_TO_AUTOKLUBY, 'wrong-dispatch');
        } finally {
            self::assertSame(StockRebuildState::PL_STOCK_AVAILABLE->value, $this->thread($order, $type)->state);
        }
    }

    public function test_complete_poland_dispatch_and_ak_receipt_path(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK);
        $type = ProcessThreadType::STOCK_REQUEST;
        $this->execute($order, $type, $this->plOperator, StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE, 'available');
        $this->execute($order, $type, $this->plOperator, StockRebuildAction::SEND_TO_AUTOKLUBY, 'send-ak');
        $this->execute($order, $type, $this->akOperator, StockRebuildAction::CONFIRM_AK_STOCK_RECEIPT, 'receive-ak');

        self::assertSame(StockRebuildState::RECEIVED_AK->value, $this->thread($order, $type)->state);
        self::assertSame(OrganizationCode::AUTOKLUBY->value, $order->items()->where('role', ItemRole::STOCK_ITEM)->sole()->current_location);
        self::assertNull($order->fresh()->closed_at);
    }

    public function test_gls_customer_completion_does_not_close_order_with_active_core_return(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK, 'GLS');
        $this->advanceStockFromPolandToAk($order);
        $this->execute($order, ProcessThreadType::STOCK_REQUEST, $this->akOperator, StockRebuildAction::SEND_TO_CUSTOMER, 'gls-customer');

        self::assertNotNull($this->thread($order, ProcessThreadType::STOCK_REQUEST)->completed_at);
        self::assertNull($order->fresh()->closed_at);
        self::assertNull($this->thread($order, ProcessThreadType::CORE_RETURN)->completed_at);
    }

    public function test_personal_pickup_path_completes_only_customer_thread(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK, 'PERSONAL_PICKUP');
        $this->advanceStockFromPolandToAk($order);
        $this->execute($order, ProcessThreadType::STOCK_REQUEST, $this->akOperator, StockRebuildAction::MARK_READY_PICKUP, 'ready-pickup');
        self::assertNull($this->thread($order, ProcessThreadType::STOCK_REQUEST)->completed_at);
        $this->execute($order, ProcessThreadType::STOCK_REQUEST, $this->akOperator, StockRebuildAction::CONFIRM_CUSTOMER_PICKUP, 'picked-up');

        self::assertNotNull($this->thread($order, ProcessThreadType::STOCK_REQUEST)->completed_at);
        self::assertNull($order->fresh()->closed_at);
    }

    public function test_replenishment_receipt_completes_only_replenishment_branch(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::AVAILABLE_IN_AK);
        $type = ProcessThreadType::STOCK_REPLENISHMENT;
        $this->execute($order, $type, $this->plOperator, StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE, 'repl-available');
        $this->execute($order, $type, $this->plOperator, StockRebuildAction::SEND_TO_AUTOKLUBY, 'repl-send');
        $this->execute($order, $type, $this->akOperator, StockRebuildAction::CONFIRM_AK_STOCK_RECEIPT, 'repl-receive');

        self::assertNotNull($this->thread($order, $type)->completed_at);
        self::assertNull($order->fresh()->closed_at);
    }

    public function test_poland_row_scope_exposes_only_its_stock_branch(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::AVAILABLE_IN_AK);

        self::assertTrue(Order::query()->visibleTo($this->plOperator)->whereKey($order)->exists());
        $visibleTypes = $order->processThreads()->visibleTo($this->plOperator)->pluck('type')->all();
        self::assertSame([ProcessThreadType::STOCK_REPLENISHMENT], $visibleTypes);
        self::assertNotContains(ProcessThreadType::STOCK_REQUEST, $visibleTypes);
        self::assertNotContains(ProcessThreadType::CORE_RETURN, $visibleTypes);

        $this->actingAs($this->plOperator)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Doplnění skladu')
            ->assertSee('Náhradní skladový kus')
            ->assertDontSee('Zákaznické plnění')
            ->assertDontSee('Čeká na vratný díl')
            ->assertDontSee('Vratka');
    }

    public function test_stock_admin_override_requires_reason_and_is_audited(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK);

        try {
            $this->execute($order, ProcessThreadType::STOCK_REQUEST, $this->akAdmin, StockRebuildAction::ADMIN_OVERRIDE, 'override-no-reason', [
                'target_state' => StockRebuildState::PL_STOCK_AVAILABLE->value,
                'reason' => ' ',
            ]);
            self::fail('Expected reason validation failure.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reason', $exception->errors());
        }

        try {
            $this->execute($order, ProcessThreadType::STOCK_REQUEST, $this->akAdmin, StockRebuildAction::ADMIN_OVERRIDE, 'override-invalid-state', [
                'target_state' => StockRebuildState::WAITING_CORE->value,
                'reason' => 'Neplatný typ cílového stavu.',
            ]);
            self::fail('Expected target state validation failure.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('target_state', $exception->errors());
        }

        $event = $this->execute($order, ProcessThreadType::STOCK_REQUEST, $this->akAdmin, StockRebuildAction::ADMIN_OVERRIDE, 'override-ok', [
            'target_state' => StockRebuildState::PL_STOCK_AVAILABLE->value,
            'reason' => 'Doložená oprava skladového stavu.',
        ]);
        self::assertSame('Doložená oprava skladového stavu.', $event->reason);
        self::assertSame(1, $order->auditLogs()->where('action', 'STOCK_REBUILD_ADMIN_OVERRIDE')->count());
    }

    private function newStockOrder(string $handover = 'GLS'): Order
    {
        $order = Order::factory()->stockRebuild()->create(['customer_handover' => $handover]);
        $this->initialFactory->createFor($order, WorkflowScenario::PENDING_STOCK_CHECK);

        return $order;
    }

    private function materialized(StockAvailabilityStatus $status, string $handover = 'GLS'): Order
    {
        $order = $this->newStockOrder($handover);
        $this->decisionService($status)->decide($order, 'decision-'.$order->id);

        return $order;
    }

    private function decisionService(StockAvailabilityStatus $status): StockBranchDecisionService
    {
        $decision = new StockAvailabilityDecision(
            $status,
            'fixture-'.$status->value,
            CarbonImmutable::parse('2026-11-12 12:00:00'),
        );
        app()->instance(ResolvesStockAvailability::class, new FixtureStockAvailabilityResolver($decision));

        return app(StockBranchDecisionService::class);
    }

    private function execute(
        Order $order,
        ProcessThreadType $type,
        User $actor,
        StockRebuildAction $action,
        string $key,
        array $data = [],
    ) {
        return $this->workflow->execute($order, $type, new StockRebuildCommand($action, $actor, $key, $data));
    }

    private function thread(Order $order, ProcessThreadType $type): ProcessThread
    {
        return $order->processThreads()->where('type', $type)->sole()->fresh();
    }

    private function advanceStockFromPolandToAk(Order $order): void
    {
        $type = ProcessThreadType::STOCK_REQUEST;
        $this->execute($order, $type, $this->plOperator, StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE, 'available-'.$order->id);
        $this->execute($order, $type, $this->plOperator, StockRebuildAction::SEND_TO_AUTOKLUBY, 'send-'.$order->id);
        $this->execute($order, $type, $this->akOperator, StockRebuildAction::CONFIRM_AK_STOCK_RECEIPT, 'receive-'.$order->id);
    }
}
