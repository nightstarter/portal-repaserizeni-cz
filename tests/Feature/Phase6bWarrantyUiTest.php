<?php

namespace Tests\Feature;

use App\Application\Orders\WarrantyCommand;
use App\Application\Orders\WarrantyWorkflow;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\UserRole;
use App\Enums\WarrantyAction;
use App\Enums\WarrantyState;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderCustomer;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase6bWarrantyUiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ak;

    private Organization $pl;

    private User $akOperator;

    private User $akAdmin;

    private User $plOperator;

    private User $readOnly;

    private WarrantyWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $this->pl = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'Polska']);
        $this->akOperator = $this->user($this->ak, UserRole::AK_OPERATOR, 'cs');
        $this->akAdmin = $this->user($this->ak, UserRole::AK_ADMIN, 'cs');
        $this->plOperator = $this->user($this->pl, UserRole::PL_OPERATOR, 'pl');
        $this->readOnly = $this->user($this->ak, UserRole::READ_ONLY, 'cs');
        $this->workflow = app(WarrantyWorkflow::class);
    }

    public function test_menu_active_state_empty_queue_and_dashboard_kpis_are_visible(): void
    {
        $empty = $this->actingAs($this->akOperator)->get(route('warranties.index'))
            ->assertOk()->assertSee('Reklamace')->assertSee('Ve zvolené frontě nejsou žádné reklamace.');
        self::assertStringContainsString('aria-current="page"', $empty->getContent());

        [$order, $item] = $this->orderAndItem('KPI-WARRANTY');
        $thread = $this->createWarranty($order, $item, 'kpi');
        $dashboard = $this->actingAs($this->akOperator)->get(route('dashboard'))->assertOk()
            ->assertSee('Aktivní reklamace')->assertSee('Reklamace čekající na AUTOKLUBY');
        self::assertSame(['active' => 1, 'autokluby' => 1, 'poland' => 0, 'transport' => 0], $dashboard->viewData('warrantyKpis'));
        self::assertSame(WarrantyState::RECEIVED_AK->value, $thread->fresh()->state);
        self::assertNull($order->fresh()->closed_at);
    }

    public function test_queue_filters_show_safe_operational_columns_and_default_only_active(): void
    {
        [$activeOrder, $activeItem] = $this->orderAndItem('ACTIVE-W');
        $active = $this->createWarranty($activeOrder, $activeItem, 'active');
        [$polandOrder, $polandItem] = $this->orderAndItem('POLAND-W');
        $poland = $this->createWarranty($polandOrder, $polandItem, 'poland');
        $this->override($poland, WarrantyState::SENT_TO_PL, 'assign Poland');
        [$doneOrder, $doneItem] = $this->orderAndItem('DONE-W');
        $done = $this->createWarranty($doneOrder, $doneItem, 'done');
        $this->override($done, WarrantyState::SENT_TO_CUSTOMER, 'finish');

        $this->actingAs($this->akOperator)->get(route('warranties.index'))
            ->assertOk()->assertSee('ACTIVE-W')->assertSee('POLAND-W')->assertDontSee('DONE-W')
            ->assertSee('Kus č. '.$activeItem->id)->assertSee('Reklamace č. 1');
        $this->actingAs($this->akOperator)->get(route('warranties.index', ['filter' => 'poland']))
            ->assertOk()->assertSee('POLAND-W')->assertDontSee('ACTIVE-W');
        $this->actingAs($this->akOperator)->get(route('warranties.index', ['filter' => 'transport']))
            ->assertOk()->assertSee('POLAND-W');
        $this->actingAs($this->akOperator)->get(route('warranties.index', ['filter' => 'completed']))
            ->assertOk()->assertSee('DONE-W')->assertDontSee('ACTIVE-W');
    }

    public function test_create_form_requires_reason_version_is_idempotent_and_escapes_note(): void
    {
        [$order, $item] = $this->orderAndItem('CREATE-W');
        $detail = $this->actingAs($this->akOperator)->get(route('orders.show', $order))->assertOk()
            ->assertSee('Založit reklamaci')->assertSee('name="expected_lock_version"', false)
            ->assertSee('name="idempotency_key"', false)->assertSee('name="reason"', false);
        self::assertStringContainsString('@media (max-width:390px)', $detail->getContent());

        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'expected_lock_version' => 0,
            'reason' => '<script>alert("x")</script> bezpečná interní poznámka',
        ];
        $url = route('orders.warranty.store', [$order, $item]);
        $this->actingAs($this->akOperator)->post($url, $payload)->assertRedirect();
        $this->actingAs($this->akOperator)->post($url, $payload)->assertRedirect();
        self::assertSame(1, $item->warrantyThreads()->count());
        $this->actingAs($this->akOperator)->get(route('orders.show', $order))->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', false)->assertDontSee('<script>alert', false);
    }

    public function test_completed_claim_one_and_active_claim_two_have_separate_timelines(): void
    {
        [$order, $item] = $this->orderAndItem('HISTORY-W');
        $first = $this->createWarranty($order, $item, 'history-1');
        $this->override($first, WarrantyState::SENT_TO_CUSTOMER, 'first completed');
        $second = $this->createWarranty($order, $item->fresh(), 'history-2');

        $page = $this->actingAs($this->akOperator)->get(route('orders.show', $order))->assertOk()
            ->assertSee('Reklamace č. 1')->assertSee('Reklamace č. 2')->assertSee('Aktivní reklamace')
            ->assertSee('id="warranty-thread-'.$first->id.'"', false)
            ->assertSee('id="warranty-thread-'.$second->id.'"', false);
        self::assertSame(2, substr_count($page->getContent(), 'class="timeline warranty-timeline"'));
        self::assertSame(WarrantyState::SENT_TO_CUSTOMER->value, $first->fresh()->state);
        self::assertSame(WarrantyState::RECEIVED_AK->value, $second->fresh()->state);
    }

    public function test_role_actions_stale_conflict_and_transport_steps_cannot_bypass_shipments(): void
    {
        [$order, $item] = $this->orderAndItem('ACTIONS-W');
        $thread = $this->createWarranty($order, $item, 'actions');
        $this->actingAs($this->akOperator)->get(route('orders.show', $order))->assertSee('Připravit k odeslání do Polska');
        $this->actingAs($this->readOnly)->get(route('orders.show', $order))->assertDontSee('Založit reklamaci')->assertDontSee('Připravit k odeslání do Polska');
        [$adminOrder] = $this->orderAndItem('ADMIN-W');
        $this->actingAs($this->akAdmin)->get(route('orders.show', $adminOrder))->assertOk()->assertSee('Založit reklamaci');

        $actionUrl = route('orders.warranty.actions', [$order, $thread, WarrantyAction::PREPARE_TO_POLAND->value]);
        $this->actingAs($this->akOperator)->post($actionUrl, [
            'idempotency_key' => 'stale-ui', 'expected_lock_version' => 99,
        ])->assertRedirect()->assertSessionHasErrors('warranty');
        self::assertSame(WarrantyState::RECEIVED_AK->value, $thread->fresh()->state);

        $this->actingAs($this->akOperator)->post($actionUrl, [
            'idempotency_key' => 'prepare-ui', 'expected_lock_version' => 0,
        ])->assertRedirect();
        $this->actingAs($this->akOperator)->get(route('orders.show', $order))->assertOk()
            ->assertSee('Otevřít zásilkový workflow')->assertSee(route('shipments.create'), false);
        $this->actingAs($this->akOperator)->postJson(route('orders.warranty.actions', [
            $order, $thread, WarrantyAction::SEND_TO_POLAND->value,
        ]), ['idempotency_key' => 'bypass', 'expected_lock_version' => 1])->assertUnprocessable();
        self::assertSame(0, Shipment::query()->count());
        self::assertSame(WarrantyState::READY_TO_PL->value, $thread->fresh()->state);
    }

    public function test_poland_is_row_scoped_gets_safe_404_and_never_sees_customer_pii_or_internal_reason(): void
    {
        [$visibleOrder, $visibleItem] = $this->orderAndItem('VISIBLE-PL');
        OrderCustomer::query()->create(['order_id' => $visibleOrder->id, 'name' => 'TAJNY KLIENT', 'email' => 'secret@example.test', 'phone' => '+420777111222', 'street' => 'Tajná 1']);
        $visible = $this->createWarranty($visibleOrder, $visibleItem, 'visible', 'interní <b>PII</b> poznámka');
        $this->override($visible, WarrantyState::SENT_TO_PL, 'assigned');
        [$hiddenOrder, $hiddenItem] = $this->orderAndItem('HIDDEN-PL');
        $hidden = $this->createWarranty($hiddenOrder, $hiddenItem, 'hidden');

        $queue = $this->actingAs($this->plOperator)->get(route('warranties.index'))->assertOk()
            ->assertSee('VISIBLE-PL')->assertDontSee('HIDDEN-PL')->assertDontSee('TAJNY KLIENT')
            ->assertDontSee('secret@example.test')->assertDontSee('interní');
        self::assertStringNotContainsString('WARRANTY_SENT_TO_PL', $queue->getContent());
        $this->actingAs($this->plOperator)->get(route('orders.show', $visibleOrder))->assertOk()
            ->assertSee('Reklamacja nr 1')->assertDontSee('TAJNY KLIENT')->assertDontSee('Tajná 1')->assertDontSee('interní')
            ->assertDontSee($visibleOrder->vehicle)->assertDontSee($visibleOrder->vin);
        $foreignItem = Item::factory()->create([
            'order_id' => $visibleOrder->id,
            'role_instance' => 2,
            'current_location' => OrganizationCode::AUTOKLUBY,
        ]);
        $this->actingAs($this->plOperator)->get(route('items.qr', $foreignItem->qr_token))->assertNotFound();
        $this->actingAs($this->plOperator)->postJson(route('orders.warranty.actions', [
            $hiddenOrder, $hidden, WarrantyAction::COMPLETE_REPAIR->value,
        ]), ['idempotency_key' => 'foreign', 'expected_lock_version' => 0])->assertNotFound();
    }

    public function test_qr_mobile_view_requires_order_access_and_shows_only_current_authorized_action(): void
    {
        [$order, $item] = $this->orderAndItem('QR-W');
        $thread = $this->createWarranty($order, $item, 'qr');
        $this->actingAs($this->akOperator)->get(route('items.qr', $item->qr_token))->assertOk()
            ->assertSee('Co mám udělat teď')->assertSee('Reklamace č. 1')->assertSee('Připravit k odeslání do Polska');
        $this->actingAs($this->plOperator)->get(route('items.qr', $item->qr_token))->assertNotFound();

        $this->override($thread, WarrantyState::SENT_TO_PL, 'assign qr');
        $this->actingAs($this->plOperator)->get(route('items.qr', $item->qr_token))->assertOk()
            ->assertSee('Co mam teraz zrobić')->assertSee('Potwierdź przyjęcie w Polsce przez przesyłkę')
            ->assertDontSee('Připravit k odeslání do Polska');
    }

    private function user(Organization $organization, UserRole $role, string $locale): User
    {
        return User::factory()->create(['organization_id' => $organization->id, 'role' => $role, 'preferred_locale' => $locale]);
    }

    private function orderAndItem(string $number): array
    {
        $order = Order::factory()->create(['order_no' => $number, 'source_order_id' => $number]);
        $item = Item::factory()->create(['order_id' => $order->id, 'current_location' => OrganizationCode::AUTOKLUBY]);
        ProcessThread::factory()->create([
            'order_id' => $order->id, 'type' => ProcessThreadType::CUSTOM_REBUILD, 'type_instance' => 1,
            'state' => 'RECEIVED_AK', 'responsible_party' => ResponsibleParty::AUTOKLUBY,
            'responsible_organization_id' => $this->ak->id,
        ]);

        return [$order, $item];
    }

    private function createWarranty(Order $order, Item $item, string $key, string $reason = 'Interní technický důvod reklamace.'): ProcessThread
    {
        return $this->workflow->create($order, $item, new WarrantyCommand(
            WarrantyAction::CREATE_CYCLE, $this->akOperator, 'create-'.$key,
            ['reason' => $reason], $item->warranty_lock_version ?? 0,
        ));
    }

    private function override(ProcessThread $thread, WarrantyState $state, string $reason): void
    {
        $fresh = $thread->fresh();
        $this->workflow->execute($fresh, new WarrantyCommand(
            WarrantyAction::ADMIN_OVERRIDE, $this->akAdmin, 'override-'.$thread->id.'-'.$state->value,
            ['target_state' => $state->value, 'reason' => $reason], $fresh->lock_version,
        ));
    }
}
