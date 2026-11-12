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
use App\Exceptions\WorkflowConflictException;
use App\Models\AuditLog;
use App\Models\LegacyProductConfiguration;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ThreadEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class CustomRebuildWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $autokluby;

    private Organization $poland;

    private User $akOperator;

    private User $akAdmin;

    private User $plOperator;

    private CustomRebuildWorkflow $workflow;

    private InitialProcessThreadFactory $initialFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autokluby = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $this->poland = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $this->akOperator = User::factory()->create(['organization_id' => $this->autokluby->id, 'role' => UserRole::AK_OPERATOR]);
        $this->akAdmin = User::factory()->create(['organization_id' => $this->autokluby->id, 'role' => UserRole::AK_ADMIN]);
        $this->plOperator = User::factory()->create(['organization_id' => $this->poland->id, 'role' => UserRole::PL_OPERATOR]);
        $this->workflow = app(CustomRebuildWorkflow::class);
        $this->initialFactory = app(InitialProcessThreadFactory::class);
    }

    public function test_complete_gls_path_is_allowed_and_closes_only_at_customer_dispatch(): void
    {
        $order = $this->newOrder('GLS');
        $this->advanceThroughPolandAndBack($order);

        self::assertNull($order->fresh()->closed_at);
        $event = $this->executeAction($order, $this->akOperator, CustomRebuildAction::SEND_TO_CUSTOMER, 'gls-final');

        self::assertSame(CustomRebuildState::SENT_CUSTOMER->value, $event->to_state);
        self::assertNotNull($order->fresh()->closed_at);
        self::assertNotNull($order->processThreads()->sole()->completed_at);
        self::assertSame(8, $order->threadEvents()->count());
        self::assertSame(7, $order->auditLogs()->count());
    }

    public function test_complete_personal_pickup_path_is_allowed_and_closes_only_after_pickup(): void
    {
        $order = $this->newOrder('PERSONAL_PICKUP');
        $this->advanceThroughPolandAndBack($order);

        $this->executeAction($order, $this->akOperator, CustomRebuildAction::MARK_READY_PICKUP, 'pickup-ready');
        self::assertNull($order->fresh()->closed_at);
        self::assertNull($order->processThreads()->sole()->completed_at);

        $event = $this->executeAction($order, $this->akOperator, CustomRebuildAction::CONFIRM_CUSTOMER_PICKUP, 'pickup-final');
        self::assertSame(CustomRebuildState::PICKED_UP->value, $event->to_state);
        self::assertNotNull($order->fresh()->closed_at);
        self::assertSame(9, $order->threadEvents()->count());
    }

    public function test_final_action_must_match_configured_handover(): void
    {
        $order = $this->newOrder('PERSONAL_PICKUP');
        $this->advanceThroughPolandAndBack($order);

        $this->expectException(WorkflowConflictException::class);
        try {
            $this->executeAction($order, $this->akOperator, CustomRebuildAction::SEND_TO_CUSTOMER, 'wrong-handover');
        } finally {
            self::assertSame(CustomRebuildState::RECEIVED_AK->value, $order->processThreads()->sole()->fresh()->state);
            self::assertNull($order->fresh()->closed_at);
        }
    }

    public function test_wrong_organization_cannot_execute_actions_or_inject_a_target_state(): void
    {
        $order = $this->newOrder();

        $this->actingAs($this->plOperator)
            ->postJson($this->actionUrl($order, CustomRebuildAction::CONFIRM_AK_RECEIPT), $this->receiptData(), ['Idempotency-Key' => 'wrong-pl'])
            ->assertForbidden();

        $this->actingAs($this->akOperator)
            ->postJson($this->actionUrl($order, CustomRebuildAction::CONFIRM_AK_RECEIPT), $this->receiptData() + ['target_state' => 'PICKED_UP'], ['Idempotency-Key' => 'inject'])
            ->assertUnprocessable();

        self::assertSame(CustomRebuildState::WAITING_CUSTOMER_ITEM->value, $order->processThreads()->sole()->fresh()->state);
        self::assertSame(1, $order->threadEvents()->count());
    }

    public function test_autokluby_operator_cannot_execute_polish_action(): void
    {
        $order = $this->newOrder();
        $this->executeAction($order, $this->akOperator, CustomRebuildAction::CONFIRM_AK_RECEIPT, 'receipt', $this->receiptData());
        $this->executeAction($order, $this->akOperator, CustomRebuildAction::SEND_TO_POLAND, 'send-pl');

        $this->actingAs($this->akOperator)
            ->postJson($this->actionUrl($order, CustomRebuildAction::CONFIRM_PL_RECEIPT), [], ['Idempotency-Key' => 'wrong-ak'])
            ->assertForbidden();

        self::assertSame(CustomRebuildState::SENT_TO_PL->value, $order->processThreads()->sole()->fresh()->state);
    }

    public function test_receipt_requires_all_control_data_and_rolls_back(): void
    {
        $order = $this->newOrder();

        $this->actingAs($this->akOperator)
            ->postJson($this->actionUrl($order, CustomRebuildAction::CONFIRM_AK_RECEIPT), [
                'inspection_result' => 'CORRECT',
            ], ['Idempotency-Key' => 'incomplete'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['routing', 'fault_code', 'axial_rods']);

        self::assertSame(CustomRebuildState::WAITING_CUSTOMER_ITEM->value, $order->processThreads()->sole()->fresh()->state);
        self::assertSame('CUSTOMER', $order->items()->sole()->fresh()->current_location);
        self::assertSame(0, $order->auditLogs()->count());
    }

    public function test_receipt_changes_only_order_routing_and_records_axial_rods_flag(): void
    {
        $order = $this->newOrder();
        $configuration = LegacyProductConfiguration::query()->create([
            'product_reference' => $order->product_code,
            'technical_type' => 'EPS',
            'default_routing' => RoutingCode::ARGO,
            'verification_status' => 'VERIFIED',
        ]);

        $this->executeAction($order, $this->akOperator, CustomRebuildAction::CONFIRM_AK_RECEIPT, 'routing', $this->receiptData([
            'routing' => RoutingCode::KRAFT->value,
            'inspection_result' => 'DIFFERENT',
            'condition_description' => 'Jiný fyzický kus.',
            'axial_rods' => true,
        ]));

        self::assertSame(RoutingCode::KRAFT, $order->fresh()->routing);
        self::assertSame(RoutingCode::ARGO, $configuration->fresh()->default_routing);
        self::assertSame(700, $order->items()->sole()->fresh()->axialRodsSurchargeCzk());
        $this->assertDatabaseHas('thread_events', [
            'order_id' => $order->id,
            'action' => CustomRebuildAction::CONFIRM_AK_RECEIPT->value,
        ]);
    }

    public function test_duplicate_idempotency_key_replays_without_second_event(): void
    {
        $order = $this->newOrder();
        $url = $this->actionUrl($order, CustomRebuildAction::CONFIRM_AK_RECEIPT);
        $payload = $this->receiptData();

        $first = $this->actingAs($this->akOperator)->postJson($url, $payload, ['Idempotency-Key' => 'duplicate'])->assertOk();
        $second = $this->actingAs($this->akOperator)->postJson($url, $payload, ['Idempotency-Key' => 'duplicate'])->assertOk();

        self::assertSame($first->json('event_id'), $second->json('event_id'));
        self::assertSame(2, $order->threadEvents()->count());
        self::assertSame(1, $order->auditLogs()->count());
    }

    public function test_two_concurrent_versions_cannot_both_write(): void
    {
        $order = $this->newOrder();
        $this->workflow->execute($order, new CustomRebuildCommand(
            CustomRebuildAction::CONFIRM_AK_RECEIPT,
            $this->akOperator,
            'concurrent-a',
            $this->receiptData(),
            0,
        ));

        $this->expectException(WorkflowConflictException::class);
        try {
            $this->workflow->execute($order, new CustomRebuildCommand(
                CustomRebuildAction::SEND_TO_POLAND,
                $this->akOperator,
                'concurrent-b',
                [],
                0,
            ));
        } finally {
            self::assertSame(2, $order->threadEvents()->count());
            self::assertSame(1, $order->auditLogs()->count());
        }
    }

    public function test_event_write_failure_rolls_back_state_receipt_and_audit(): void
    {
        $order = $this->newOrder();
        Event::listen('eloquent.creating: '.ThreadEvent::class, static function (): never {
            throw new RuntimeException('event write failed');
        });

        try {
            $this->executeAction($order, $this->akOperator, CustomRebuildAction::CONFIRM_AK_RECEIPT, 'event-failure', $this->receiptData());
            self::fail('Expected event failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('event write failed', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ThreadEvent::class);
        }

        self::assertSame(CustomRebuildState::WAITING_CUSTOMER_ITEM->value, $order->processThreads()->sole()->fresh()->state);
        self::assertSame('CUSTOMER', $order->items()->sole()->fresh()->current_location);
        self::assertSame(RoutingCode::ARGO, $order->fresh()->routing);
        self::assertSame(1, $order->threadEvents()->count());
        self::assertSame(0, $order->auditLogs()->count());
    }

    public function test_audit_write_failure_rolls_back_event_and_state(): void
    {
        $order = $this->newOrder();
        Event::listen('eloquent.creating: '.AuditLog::class, static function (): never {
            throw new RuntimeException('audit write failed');
        });

        try {
            $this->executeAction($order, $this->akOperator, CustomRebuildAction::CONFIRM_AK_RECEIPT, 'audit-failure', $this->receiptData());
            self::fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('audit write failed', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }

        self::assertSame(CustomRebuildState::WAITING_CUSTOMER_ITEM->value, $order->processThreads()->sole()->fresh()->state);
        self::assertSame(1, $order->threadEvents()->count());
        self::assertSame(0, $order->auditLogs()->count());
    }

    public function test_admin_override_requires_non_empty_reason(): void
    {
        $order = $this->newOrder();

        $this->actingAs($this->akAdmin)
            ->postJson($this->actionUrl($order, CustomRebuildAction::ADMIN_OVERRIDE), [
                'target_state' => CustomRebuildState::SENT_TO_PL->value,
                'reason' => '   ',
            ], ['Idempotency-Key' => 'override-no-reason'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        self::assertSame(CustomRebuildState::WAITING_CUSTOMER_ITEM->value, $order->processThreads()->sole()->fresh()->state);
    }

    public function test_admin_override_has_full_event_and_audit(): void
    {
        $order = $this->newOrder();

        $event = $this->executeAction($order, $this->akAdmin, CustomRebuildAction::ADMIN_OVERRIDE, 'override-ok', [
            'target_state' => CustomRebuildState::SENT_TO_PL->value,
            'reason' => 'Oprava doloženého provozního kroku.',
        ]);

        self::assertSame(CustomRebuildState::SENT_TO_PL->value, $event->to_state);
        self::assertSame('Oprava doloženého provozního kroku.', $event->reason);
        $audit = $order->auditLogs()->sole();
        self::assertSame('CUSTOM_REBUILD_ADMIN_OVERRIDE', $audit->action);
        self::assertSame($event->id, $audit->new_values['thread_event_id']);
        self::assertSame('Oprava doloženého provozního kroku.', $audit->reason);
    }

    private function newOrder(string $handover = 'GLS'): Order
    {
        $order = Order::factory()->create(['customer_handover' => $handover, 'routing' => RoutingCode::ARGO]);
        $this->initialFactory->createFor($order, WorkflowScenario::CUSTOM_REBUILD);

        return $order;
    }

    private function advanceThroughPolandAndBack(Order $order): void
    {
        $this->executeAction($order, $this->akOperator, CustomRebuildAction::CONFIRM_AK_RECEIPT, 'receipt-'.$order->id, $this->receiptData());
        $this->executeAction($order, $this->akOperator, CustomRebuildAction::SEND_TO_POLAND, 'to-pl-'.$order->id);
        $this->executeAction($order, $this->plOperator, CustomRebuildAction::CONFIRM_PL_RECEIPT, 'pl-receipt-'.$order->id);
        $this->executeAction($order, $this->plOperator, CustomRebuildAction::MARK_REPAIRED_PL, 'repaired-'.$order->id);
        $this->executeAction($order, $this->plOperator, CustomRebuildAction::SEND_TO_AUTOKLUBY, 'to-ak-'.$order->id);
        $this->executeAction($order, $this->akOperator, CustomRebuildAction::CONFIRM_AK_RETURN_RECEIPT, 'ak-return-'.$order->id);
    }

    private function executeAction(
        Order $order,
        User $actor,
        CustomRebuildAction $action,
        string $key,
        array $data = [],
    ): ThreadEvent {
        return $this->workflow->execute($order, new CustomRebuildCommand($action, $actor, $key, $data));
    }

    private function receiptData(array $overrides = []): array
    {
        return array_replace([
            'inspection_result' => 'CORRECT',
            'condition_description' => null,
            'routing' => RoutingCode::ARGO->value,
            'fault_code' => 'OIL_LEAK',
            'fault_note' => null,
            'axial_rods' => false,
        ], $overrides);
    }

    private function actionUrl(Order $order, CustomRebuildAction $action): string
    {
        return route('orders.custom-rebuild.actions', [$order, $action->value]);
    }
}
