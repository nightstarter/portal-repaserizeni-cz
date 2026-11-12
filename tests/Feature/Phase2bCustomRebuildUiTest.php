<?php

namespace Tests\Feature;

use App\Application\Orders\CustomRebuildCommand;
use App\Application\Orders\CustomRebuildWorkflow;
use App\Application\Orders\InitialProcessThreadFactory;
use App\Enums\CustomRebuildAction;
use App\Enums\CustomRebuildState;
use App\Enums\OrganizationCode;
use App\Enums\RoutingCode;
use App\Enums\UserRole;
use App\Enums\WorkflowScenario;
use App\Models\Order;
use App\Models\OrderCustomer;
use App\Models\Organization;
use App\Models\ThreadEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2bCustomRebuildUiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $autokluby;

    private Organization $poland;

    private User $akOperator;

    private User $akAdmin;

    private User $plOperator;

    private InitialProcessThreadFactory $factory;

    private CustomRebuildWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autokluby = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $this->poland = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $this->akOperator = User::factory()->create([
            'organization_id' => $this->autokluby->id,
            'role' => UserRole::AK_OPERATOR,
            'preferred_locale' => 'cs',
        ]);
        $this->akAdmin = User::factory()->create([
            'organization_id' => $this->autokluby->id,
            'role' => UserRole::AK_ADMIN,
            'preferred_locale' => 'cs',
        ]);
        $this->plOperator = User::factory()->create([
            'organization_id' => $this->poland->id,
            'role' => UserRole::PL_OPERATOR,
            'preferred_locale' => 'pl',
        ]);
        $this->factory = app(InitialProcessThreadFactory::class);
        $this->workflow = app(CustomRebuildWorkflow::class);
    }

    public function test_autokluby_dashboard_shows_derived_custom_rebuild_operational_columns(): void
    {
        $order = $this->newOrder(['order_no' => 'AK-UI-100', 'product_code' => 'REP-UI', 'vehicle' => 'VW Passat', 'vin' => 'WVWZZZTESTVIN0001']);

        $this->actingAs($this->akOperator)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('AK-UI-100')
            ->assertSee('REP-UI')
            ->assertSee('VW Passat')
            ->assertSee('WVWZZZTESTVIN0001')
            ->assertSee('EPS')
            ->assertSee('ARGO')
            ->assertSee('Čeká na řízení od zákazníka')
            ->assertSee('AUTOKLUBY')
            ->assertSee('Potvrdit příjem AUTOKLUBY')
            ->assertSee('Poslední událost');
    }

    public function test_poland_dashboard_is_row_level_limited_to_authorized_custom_rebuilds(): void
    {
        $visible = $this->newOrder(['order_no' => 'PL-VISIBLE']);
        $hidden = $this->newOrder(['order_no' => 'PL-HIDDEN']);

        $this->actingAs($this->plOperator)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('PL-VISIBLE')
            ->assertDontSee('PL-HIDDEN');

        $this->sendToPoland($visible);

        $this->actingAs($this->plOperator)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('PL-VISIBLE')
            ->assertDontSee('PL-HIDDEN')
            ->assertSee('Potwierdź przyjęcie w Polsce');
    }

    public function test_poland_direct_access_to_foreign_custom_rebuild_is_forbidden(): void
    {
        $foreign = $this->newOrder(['order_no' => 'FOREIGN-ORDER']);

        $this->actingAs($this->plOperator)
            ->get(route('orders.show', $foreign))
            ->assertForbidden();
    }

    public function test_poland_detail_and_dashboard_never_show_customer_contact_pii(): void
    {
        $order = $this->newOrder(['order_no' => 'PII-SAFE']);
        OrderCustomer::factory()->for($order)->create([
            'name' => 'Citlivý Zákazník',
            'email' => 'secret-customer@example.test',
            'phone' => '+420 777 999 999',
            'street' => 'Tajná 123',
        ]);
        $this->sendToPoland($order);

        foreach ([route('dashboard'), route('orders.show', $order)] as $url) {
            $this->actingAs($this->plOperator)->get($url)
                ->assertOk()
                ->assertDontSee('Citlivý Zákazník')
                ->assertDontSee('secret-customer@example.test')
                ->assertDontSee('+420 777 999 999')
                ->assertDontSee('Tajná 123');
        }
    }

    public function test_detail_shows_only_currently_allowed_action_and_direct_forbidden_action_still_fails(): void
    {
        $order = $this->newOrder();

        $this->actingAs($this->akOperator)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Potvrdit příjem AUTOKLUBY')
            ->assertDontSee('Potvrdit příjem v Polsku')
            ->assertDontSee('Administrátorský override');

        $this->sendToPoland($order);

        $this->actingAs($this->plOperator)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Potwierdź przyjęcie w Polsce')
            ->assertDontSee($this->actionUrl($order, CustomRebuildAction::SEND_TO_POLAND), false)
            ->assertDontSee('Nadpisanie administracyjne');

        $this->actingAs($this->plOperator)
            ->postJson($this->actionUrl($order, CustomRebuildAction::MARK_REPAIRED_PL), [], ['Idempotency-Key' => 'forbidden-skip'])
            ->assertConflict();
    }

    public function test_forms_contain_csrf_idempotency_and_version_and_repeated_post_is_idempotent(): void
    {
        $order = $this->newOrder();
        $page = $this->actingAs($this->akOperator)->get(route('orders.show', $order))->assertOk();

        $page->assertSee('name="_token"', false)
            ->assertSee('name="idempotency_key"', false)
            ->assertSee('name="expected_lock_version" value="0"', false);

        $payload = $this->receiptData() + [
            'idempotency_key' => 'ui-repeat-key',
            'expected_lock_version' => 0,
        ];
        $url = $this->actionUrl($order, CustomRebuildAction::CONFIRM_AK_RECEIPT);

        $this->actingAs($this->akOperator)->post($url, $payload)->assertRedirect(route('orders.show', $order));
        $this->actingAs($this->akOperator)->post($url, $payload)->assertRedirect(route('orders.show', $order));

        self::assertSame(2, $order->threadEvents()->count());
        self::assertSame(1, $order->auditLogs()->count());
    }

    public function test_stale_branch_version_redirects_to_current_state_without_partial_write(): void
    {
        $order = $this->newOrder();
        $this->execute($order, $this->akOperator, CustomRebuildAction::CONFIRM_AK_RECEIPT, 'version-first', $this->receiptData());

        $response = $this->actingAs($this->akOperator)->post(
            $this->actionUrl($order, CustomRebuildAction::SEND_TO_POLAND),
            ['idempotency_key' => 'version-stale', 'expected_lock_version' => 0],
        );

        $response->assertRedirect(route('orders.show', $order));
        $this->actingAs($this->akOperator)->get($response->headers->get('Location'))
            ->assertSee('Aktuální stav: Přijaté AUTOKLUBY');
        self::assertSame(CustomRebuildState::RECEIVED_AK->value, $order->processThreads()->sole()->fresh()->state);
        self::assertSame(2, $order->threadEvents()->count());
    }

    public function test_guest_qr_redirects_to_login_and_returns_after_authentication(): void
    {
        $order = $this->newOrder();
        $qrUrl = route('items.qr', $order->items()->sole()->qr_token);

        $this->get($qrUrl)
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', $qrUrl);

        $this->post(route('login.store'), [
            'email' => $this->akOperator->email,
            'password' => 'TestPassword!123',
        ])->assertRedirect($qrUrl);

        $this->get($qrUrl)
            ->assertOk()
            ->assertSee('Co mám udělat teď')
            ->assertSee('Potvrdit příjem AUTOKLUBY');
    }

    public function test_foreign_and_invalid_qr_tokens_have_same_not_found_response(): void
    {
        $order = $this->newOrder();

        $this->actingAs($this->plOperator)
            ->get(route('items.qr', $order->items()->sole()->qr_token))
            ->assertNotFound();
        $this->actingAs($this->plOperator)
            ->get('/q/'.str_repeat('x', 48))
            ->assertNotFound();

        $this->sendToPoland($order);
        $this->actingAs($this->plOperator)
            ->get(route('items.qr', $order->items()->sole()->qr_token))
            ->assertOk()
            ->assertSee('Co mam teraz zrobić');
    }

    public function test_czech_and_polish_states_actions_fields_and_validation_are_localized(): void
    {
        $order = $this->newOrder();

        $this->actingAs($this->akOperator)->get(route('orders.show', $order))
            ->assertSee('Čeká na řízení od zákazníka')
            ->assertSee('Doručený kus')
            ->assertSee('Závada');

        $plSpeakingAk = User::factory()->create([
            'organization_id' => $this->autokluby->id,
            'role' => UserRole::AK_OPERATOR,
            'preferred_locale' => 'pl',
        ]);
        $this->actingAs($plSpeakingAk)
            ->post($this->actionUrl($order, CustomRebuildAction::CONFIRM_AK_RECEIPT), [
                'idempotency_key' => 'pl-validation',
                'expected_lock_version' => 0,
            ])
            ->assertSessionHasErrors(['inspection_result', 'routing', 'fault_code', 'axial_rods']);

        $this->actingAs($plSpeakingAk)->get(route('orders.show', $order))
            ->assertSee('Oczekuje na przekładnię od klienta')
            ->assertSee('Dostarczona część')
            ->assertSee('Usterka');
    }

    public function test_admin_override_control_is_admin_only_and_requires_reason_and_confirmation(): void
    {
        $order = $this->newOrder();

        $this->actingAs($this->akOperator)->get(route('orders.show', $order))
            ->assertDontSee('Administrátorský override');
        $this->actingAs($this->akAdmin)->get(route('orders.show', $order))
            ->assertSee('Administrátorský override')
            ->assertSee('Potvrzuji výjimečnou změnu');

        $url = $this->actionUrl($order, CustomRebuildAction::ADMIN_OVERRIDE);
        $this->actingAs($this->akAdmin)->post($url, [
            'idempotency_key' => 'override-missing-reason',
            'expected_lock_version' => 0,
            'target_state' => CustomRebuildState::SENT_TO_PL->value,
            'override_confirmed' => '1',
        ])->assertSessionHasErrors('reason');

        $this->actingAs($this->akOperator)->postJson($url, [
            'target_state' => CustomRebuildState::SENT_TO_PL->value,
            'reason' => 'Not allowed',
            'override_confirmed' => '1',
        ], ['Idempotency-Key' => 'operator-override'])->assertForbidden();

        $this->actingAs($this->akAdmin)->post($url, [
            'idempotency_key' => 'override-valid',
            'expected_lock_version' => 0,
            'target_state' => CustomRebuildState::SENT_TO_PL->value,
            'reason' => 'Doložená oprava stavu.',
            'override_confirmed' => '1',
        ])->assertRedirect(route('orders.show', $order));

        $this->assertDatabaseHas('thread_events', ['order_id' => $order->id, 'action' => 'ADMIN_OVERRIDE']);
        $this->assertDatabaseHas('audit_log', ['order_id' => $order->id, 'action' => 'CUSTOM_REBUILD_ADMIN_OVERRIDE']);
    }

    public function test_polish_timeline_exposes_only_safe_workflow_metadata(): void
    {
        $order = $this->newOrder(['order_no' => 'SAFE-TIMELINE']);
        OrderCustomer::factory()->for($order)->create(['email' => 'timeline-pii@example.test']);
        $this->sendToPoland($order);
        $receipt = $order->threadEvents()->where('action', CustomRebuildAction::CONFIRM_AK_RECEIPT)->sole();
        $receipt->update(['metadata' => $receipt->metadata + [
            'internal_note' => 'INTERNAL-SECRET-NOTE',
            'customer_email' => 'timeline-pii@example.test',
            'token' => 'SECRET-TOKEN',
        ]]);
        ThreadEvent::query()->create([
            'process_thread_id' => $order->processThreads()->sole()->id,
            'order_id' => $order->id,
            'action' => 'ADMIN_OVERRIDE',
            'from_state' => 'SENT_TO_PL',
            'to_state' => 'SENT_TO_PL',
            'actor_type' => 'AUTOKLUBY',
            'actor_user_id' => $this->akAdmin->id,
            'actor_organization_id' => $this->autokluby->id,
            'responsible_organization_id' => $this->poland->id,
            'reason' => 'SECRET-ADMIN-REASON',
            'metadata' => ['private_payload' => 'PRIVATE-AUDIT-DATA'],
        ]);

        $this->actingAs($this->plOperator)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Wyciek oleju')
            ->assertDontSee('INTERNAL-SECRET-NOTE')
            ->assertDontSee('timeline-pii@example.test')
            ->assertDontSee('SECRET-TOKEN')
            ->assertDontSee('SECRET-ADMIN-REASON')
            ->assertDontSee('PRIVATE-AUDIT-DATA');
    }

    private function newOrder(array $attributes = []): Order
    {
        $order = Order::factory()->create(array_replace([
            'customer_handover' => 'GLS',
            'routing' => RoutingCode::ARGO,
        ], $attributes));
        $this->factory->createFor($order, WorkflowScenario::CUSTOM_REBUILD);

        return $order;
    }

    private function sendToPoland(Order $order): void
    {
        $this->execute($order, $this->akOperator, CustomRebuildAction::CONFIRM_AK_RECEIPT, 'receipt-'.$order->id, $this->receiptData());
        $this->execute($order, $this->akOperator, CustomRebuildAction::SEND_TO_POLAND, 'send-'.$order->id);
    }

    private function execute(Order $order, User $actor, CustomRebuildAction $action, string $key, array $data = []): ThreadEvent
    {
        return $this->workflow->execute($order, new CustomRebuildCommand($action, $actor, $key, $data));
    }

    private function receiptData(): array
    {
        return [
            'inspection_result' => 'CORRECT',
            'condition_description' => 'Technický popis bez PII',
            'routing' => RoutingCode::ARGO->value,
            'fault_code' => 'OIL_LEAK',
            'fault_note' => 'Prověřit těsnění',
            'axial_rods' => false,
        ];
    }

    private function actionUrl(Order $order, CustomRebuildAction $action): string
    {
        return route('orders.custom-rebuild.actions', [$order, $action->value]);
    }
}
