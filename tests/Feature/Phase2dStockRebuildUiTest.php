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
use App\Models\Order;
use App\Models\OrderCustomer;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixtureStockAvailabilityResolver;
use Tests\TestCase;

class Phase2dStockRebuildUiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $autokluby;

    private Organization $poland;

    private User $akOperator;

    private User $plOperator;

    private InitialProcessThreadFactory $factory;

    private StockRebuildWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-12 10:00:00');
        $this->autokluby = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $this->poland = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $this->akOperator = User::factory()->create([
            'organization_id' => $this->autokluby->id,
            'role' => UserRole::AK_OPERATOR,
            'preferred_locale' => 'cs',
        ]);
        $this->plOperator = User::factory()->create([
            'organization_id' => $this->poland->id,
            'role' => UserRole::PL_OPERATOR,
            'preferred_locale' => 'pl',
        ]);
        $this->factory = app(InitialProcessThreadFactory::class);
        $this->workflow = app(StockRebuildWorkflow::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_autokluby_dashboard_and_detail_show_three_parallel_branches(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::AVAILABLE_IN_AK, ['order_no' => 'STOCK-THREE']);

        $this->actingAs($this->akOperator)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Čeká na kontrolu skladu')
            ->assertSee('Zákaznické plnění')
            ->assertSee('Doplnění skladu')
            ->assertSee('Čeká na vratný díl')
            ->assertSee('STOCK-THREE', false);

        $this->actingAs($this->akOperator)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Paralelní procesní větve')
            ->assertSee('Připraveno k expedici zákazníkovi')
            ->assertSee('Čeká na skladový kus z Polska')
            ->assertSee('Čeká na vratný díl');
    }

    public function test_pending_stock_check_explains_missing_and_stale_snapshot_without_manual_decision(): void
    {
        $missing = $this->pending(['order_no' => 'PENDING-MISSING']);

        $this->actingAs($this->akOperator)->get(route('orders.show', $missing))
            ->assertOk()
            ->assertSee('Čeká se na aktuální informaci z Pohody')
            ->assertSee('Aktuální skladový snapshot chybí')
            ->assertSee('Dostupnost nelze potvrdit ručně')
            ->assertDontSee('CONFIRM_STOCK_AVAILABLE_IN_AK');

        $this->bindDecision(StockAvailabilityStatus::STALE, 'stale-fixture', CarbonImmutable::parse('2026-11-10 08:00:00'));
        $this->actingAs($this->akOperator)->get(route('orders.show', $missing))
            ->assertSee('Poslední skladový snapshot je zastaralý')
            ->assertSee('10.11.2026 08:00');

        self::assertFalse(collect(app('router')->getRoutes()->getRoutes())->contains(
            fn ($route): bool => str_contains($route->uri(), 'stock-decision'),
        ));
    }

    public function test_pending_order_is_invisible_to_poland(): void
    {
        $order = $this->pending(['order_no' => 'PENDING-HIDDEN']);

        $this->actingAs($this->plOperator)->get(route('dashboard'))->assertOk()->assertDontSee('PENDING-HIDDEN');
        $this->actingAs($this->plOperator)->get(route('orders.show', $order))->assertForbidden();
    }

    public function test_polish_dashboard_has_separate_operational_queues(): void
    {
        $new = $this->materialized(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK, ['order_no' => 'PL-NEW']);
        $securing = $this->materialized(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK, ['order_no' => 'PL-ETA']);
        $available = $this->materialized(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK, ['order_no' => 'PL-AVAILABLE']);
        $sent = $this->materialized(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK, ['order_no' => 'PL-SENT']);
        $this->execute($securing, ProcessThreadType::STOCK_REQUEST, $this->plOperator, StockRebuildAction::SET_PL_STOCK_ETA, 'eta', ['eta' => '2026-12-01 10:00:00']);
        $this->execute($available, ProcessThreadType::STOCK_REQUEST, $this->plOperator, StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE, 'available');
        $this->execute($sent, ProcessThreadType::STOCK_REQUEST, $this->plOperator, StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE, 'sent-available');
        $this->execute($sent, ProcessThreadType::STOCK_REQUEST, $this->plOperator, StockRebuildAction::SEND_TO_AUTOKLUBY, 'sent-dispatch');

        $this->actingAs($this->plOperator)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Nowe zapotrzebowania na część')
            ->assertSee('Polska organizuje część')
            ->assertSee('Dostępne – oczekuje na wysyłkę')
            ->assertSee('Wysłane do AUTOKLUBY');

        $this->actingAs($this->plOperator)->get(route('dashboard', ['queue' => 'pl_new']))
            ->assertSee('PL-NEW')->assertDontSee('PL-ETA')->assertDontSee('PL-AVAILABLE')->assertDontSee('PL-SENT');
    }

    public function test_poland_sees_only_assigned_branch_without_customer_pii_or_core(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::AVAILABLE_IN_AK, ['order_no' => 'PL-SAFE']);
        OrderCustomer::factory()->for($order)->create([
            'name' => 'Tajný zákazník', 'email' => 'secret@example.test', 'phone' => '+420777111222', 'street' => 'Tajná 1',
        ]);
        $event = $this->thread($order, ProcessThreadType::STOCK_REPLENISHMENT)->events()->first();
        $event->update([
            'reason' => 'TAJNY-ADMIN-DUVOD',
            'metadata' => ($event->metadata ?? []) + ['customer_email' => 'secret@example.test', 'internal_note' => 'TAJNA-POZNAMKA'],
        ]);

        foreach ([route('dashboard'), route('orders.show', $order)] as $url) {
            $this->actingAs($this->plOperator)->get($url)
                ->assertOk()
                ->assertSee('Uzupełnienie magazynu')
                ->assertDontSee('Realizacja dla klienta')
                ->assertDontSee('Zwrot starej części')
                ->assertDontSee('secret@example.test')
                ->assertDontSee('+420777111222')
                ->assertDontSee('Tajná 1')
                ->assertDontSee('TAJNY-ADMIN-DUVOD')
                ->assertDontSee('TAJNA-POZNAMKA');
        }
    }

    public function test_eta_is_required_future_changeable_and_each_change_is_an_event(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK);
        $url = $this->actionUrl($order, ProcessThreadType::STOCK_REQUEST, StockRebuildAction::SET_PL_STOCK_ETA);

        $this->actingAs($this->plOperator)->post($url, [
            'idempotency_key' => 'eta-missing', 'expected_lock_version' => 1,
        ])->assertSessionHasErrors('eta');
        $this->actingAs($this->plOperator)->post($url, [
            'idempotency_key' => 'eta-past', 'expected_lock_version' => 1, 'eta' => '2026-11-11 09:00:00',
        ])->assertSessionHasErrors('eta');

        $this->postAction($order, ProcessThreadType::STOCK_REQUEST, StockRebuildAction::SET_PL_STOCK_ETA, 'eta-one', 1, ['eta' => '2026-12-01 09:00:00']);
        $this->postAction($order, ProcessThreadType::STOCK_REQUEST, StockRebuildAction::SET_PL_STOCK_ETA, 'eta-two', 2, ['eta' => '2026-12-08 11:30:00']);

        self::assertSame(2, $order->threadEvents()->where('action', StockRebuildAction::SET_PL_STOCK_ETA)->count());
        self::assertSame('2026-12-08 11:30', $this->thread($order, ProcessThreadType::STOCK_REQUEST)->eta?->format('Y-m-d H:i'));
    }

    public function test_polish_availability_dispatch_and_autokluby_receipt_are_available_from_ui(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK);

        $this->actingAs($this->plOperator)->get(route('orders.show', $order))
            ->assertSee('Potwierdź dostępność w Polsce')
            ->assertSee('Ustaw przewidywany termin dostępności')
            ->assertSee('name="_token"', false)
            ->assertSee('name="idempotency_key"', false)
            ->assertSee('name="expected_lock_version" value="1"', false);
        $this->actingAs($this->plOperator)->postJson(
            $this->actionUrl($order, ProcessThreadType::STOCK_REQUEST, StockRebuildAction::SEND_TO_CUSTOMER),
            [],
            ['Idempotency-Key' => 'forbidden-polish-customer-action'],
        )->assertForbidden();
        $this->postAction($order, ProcessThreadType::STOCK_REQUEST, StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE, 'available', 1);
        $this->actingAs($this->plOperator)->get(route('orders.show', $order))->assertSee('Wyślij do AUTOKLUBY');
        $this->postAction($order, ProcessThreadType::STOCK_REQUEST, StockRebuildAction::SEND_TO_AUTOKLUBY, 'dispatch', 2);
        $this->actingAs($this->akOperator)->get(route('orders.show', $order))->assertSee('Potvrdit příjem skladového kusu AUTOKLUBY');
        $this->postAction($order, ProcessThreadType::STOCK_REQUEST, StockRebuildAction::CONFIRM_AK_STOCK_RECEIPT, 'receipt', 3, actor: $this->akOperator);

        self::assertSame(StockRebuildState::RECEIVED_AK->value, $this->thread($order, ProcessThreadType::STOCK_REQUEST)->state);
    }

    public function test_gls_and_personal_pickup_actions_complete_only_customer_branch(): void
    {
        $gls = $this->materialized(StockAvailabilityStatus::AVAILABLE_IN_AK, ['customer_handover' => 'GLS']);
        $this->actingAs($this->akOperator)->get(route('orders.show', $gls))->assertSee('Odeslat zákazníkovi')->assertDontSee('Připravit k osobnímu odběru');
        $this->postAction($gls, ProcessThreadType::STOCK_REQUEST, StockRebuildAction::SEND_TO_CUSTOMER, 'gls', 1, actor: $this->akOperator);

        $pickup = $this->materialized(StockAvailabilityStatus::AVAILABLE_IN_AK, ['customer_handover' => 'PERSONAL_PICKUP']);
        $this->postAction($pickup, ProcessThreadType::STOCK_REQUEST, StockRebuildAction::MARK_READY_PICKUP, 'ready', 1, actor: $this->akOperator);
        $this->actingAs($this->akOperator)->get(route('orders.show', $pickup))->assertSee('Potvrdit převzetí zákazníkem');
        $this->postAction($pickup, ProcessThreadType::STOCK_REQUEST, StockRebuildAction::CONFIRM_CUSTOMER_PICKUP, 'picked', 2, actor: $this->akOperator);

        self::assertNull($gls->fresh()->closed_at);
        self::assertNull($pickup->fresh()->closed_at);
    }

    public function test_physical_items_have_distinct_roles_qr_and_row_level_access(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::AVAILABLE_IN_AK);
        $items = $order->items()->get();
        self::assertSame(3, $items->pluck('qr_token')->unique()->count());
        self::assertEqualsCanonicalizing([
            ItemRole::STOCK_ITEM,
            ItemRole::STOCK_REPLACEMENT,
            ItemRole::CORE_RETURN,
        ], $items->pluck('role')->all());

        foreach ($items as $item) {
            $this->actingAs($this->akOperator)->get(route('items.qr', $item->qr_token))
                ->assertOk()->assertSee(__('app.item_roles.'.$item->role->value));
        }

        $this->actingAs($this->plOperator)->get(route('items.qr', $items->firstWhere('role', ItemRole::STOCK_REPLACEMENT)->qr_token))->assertOk();
        $this->actingAs($this->plOperator)->get(route('items.qr', $items->firstWhere('role', ItemRole::STOCK_ITEM)->qr_token))->assertNotFound();
        $this->actingAs($this->plOperator)->get(route('items.qr', $items->firstWhere('role', ItemRole::CORE_RETURN)->qr_token))->assertNotFound();
    }

    public function test_detail_explains_why_order_remains_open(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::AVAILABLE_IN_AK);
        $this->postAction($order, ProcessThreadType::STOCK_REQUEST, StockRebuildAction::SEND_TO_CUSTOMER, 'customer-done', 1, actor: $this->akOperator);

        $this->actingAs($this->akOperator)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Zakázku zatím nelze uzavřít')
            ->assertSee('Doplnění skladu')
            ->assertSee('Vratka');
        self::assertNull($order->fresh()->closed_at);
    }

    public function test_version_conflict_and_duplicate_idempotency_are_safe(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK);
        $url = $this->actionUrl($order, ProcessThreadType::STOCK_REQUEST, StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE);
        $payload = ['idempotency_key' => 'same-http-key', 'expected_lock_version' => 1];

        $this->actingAs($this->plOperator)->post($url, $payload)->assertRedirect(route('orders.show', $order));
        $this->actingAs($this->plOperator)->post($url, $payload)->assertRedirect(route('orders.show', $order));
        self::assertSame(1, $order->threadEvents()->where('action', StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE)->count());

        $staleUrl = $this->actionUrl($order, ProcessThreadType::STOCK_REQUEST, StockRebuildAction::SEND_TO_AUTOKLUBY);
        $this->actingAs($this->plOperator)->post($staleUrl, [
            'idempotency_key' => 'stale-version', 'expected_lock_version' => 1,
        ])->assertRedirect(route('orders.show', $order));
        self::assertSame(StockRebuildState::PL_STOCK_AVAILABLE->value, $this->thread($order, ProcessThreadType::STOCK_REQUEST)->state);
    }

    public function test_czech_and_polish_stock_ui_is_localized(): void
    {
        $order = $this->materialized(StockAvailabilityStatus::NOT_AVAILABLE_IN_AK);
        $this->actingAs($this->akOperator)->get(route('orders.show', $order))
            ->assertSee('Čeká na skladový kus z Polska')
            ->assertSee('Informace o skladu');
        $this->actingAs($this->plOperator)->get(route('orders.show', $order))
            ->assertSee('Oczekuje na część magazynową z Polski')
            ->assertSee('Potwierdź dostępność w Polsce')
            ->assertSee('Ustaw przewidywany termin dostępności');
    }

    private function pending(array $attributes = []): Order
    {
        $order = Order::factory()->stockRebuild()->create($attributes);
        $this->factory->createFor($order, WorkflowScenario::PENDING_STOCK_CHECK);

        return $order;
    }

    private function materialized(StockAvailabilityStatus $status, array $attributes = []): Order
    {
        $order = $this->pending($attributes);
        $this->bindDecision($status);
        app(StockBranchDecisionService::class)->decide($order, 'decision-'.$order->id);

        return $order;
    }

    private function bindDecision(
        StockAvailabilityStatus $status,
        ?string $reference = null,
        ?CarbonImmutable $observedAt = null,
    ): void {
        app()->instance(ResolvesStockAvailability::class, new FixtureStockAvailabilityResolver(new StockAvailabilityDecision(
            $status,
            $reference ?? 'phase-2d-'.$status->value,
            $observedAt ?? CarbonImmutable::parse('2026-11-12 09:00:00'),
        )));
    }

    private function execute(Order $order, ProcessThreadType $type, User $actor, StockRebuildAction $action, string $key, array $data = []): void
    {
        $this->workflow->execute($order, $type, new StockRebuildCommand($action, $actor, $key, $data));
    }

    private function postAction(
        Order $order,
        ProcessThreadType $type,
        StockRebuildAction $action,
        string $key,
        int $version,
        array $data = [],
        ?User $actor = null,
    ): void {
        $this->actingAs($actor ?? $this->plOperator)->post($this->actionUrl($order, $type, $action), $data + [
            'idempotency_key' => $key,
            'expected_lock_version' => $version,
        ])->assertRedirect(route('orders.show', $order));
    }

    private function actionUrl(Order $order, ProcessThreadType $type, StockRebuildAction $action): string
    {
        return route('orders.stock-rebuild.actions', [$order, $type->value, $action->value]);
    }

    private function thread(Order $order, ProcessThreadType $type): ProcessThread
    {
        return $order->processThreads()->where('type', $type)->sole()->fresh();
    }
}
