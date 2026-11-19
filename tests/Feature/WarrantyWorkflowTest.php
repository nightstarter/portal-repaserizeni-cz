<?php

namespace Tests\Feature;

use App\Application\Orders\WarrantyCommand;
use App\Application\Orders\WarrantyWorkflow;
use App\Application\Shipments\ShipmentCommand;
use App\Application\Shipments\ShipmentService;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\ShipmentCarrier;
use App\Enums\ShipmentDirection;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Enums\WarrantyAction;
use App\Enums\WarrantyState;
use App\Exceptions\WorkflowConflictException;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderCustomer;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\Shipment;
use App\Models\ThreadEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WarrantyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ak;

    private Organization $pl;

    private User $akOperator;

    private User $akAdmin;

    private User $plOperator;

    private User $readOnly;

    private WarrantyWorkflow $workflow;

    private ShipmentService $shipments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $this->pl = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'Polsko']);
        $this->akOperator = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::AK_OPERATOR]);
        $this->akAdmin = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::AK_ADMIN]);
        $this->plOperator = User::factory()->create(['organization_id' => $this->pl->id, 'role' => UserRole::PL_OPERATOR]);
        $this->readOnly = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::READ_ONLY]);
        $this->workflow = app(WarrantyWorkflow::class);
        $this->shipments = app(ShipmentService::class);
    }

    public function test_creates_claim_one_under_original_order_and_is_idempotent(): void
    {
        [$order, $item] = $this->orderAndItem('claim-one');
        $thread = $this->createCycle($order, $item, 'create-one');
        $replay = $this->createCycle($order, $item, 'create-one');

        self::assertSame($thread->id, $replay->id);
        self::assertSame(1, $thread->warranty_cycle);
        self::assertSame($order->id, $thread->order_id);
        self::assertSame($item->id, $thread->item_id);
        self::assertSame(WarrantyState::RECEIVED_AK->value, $thread->state);
        self::assertSame($order->order_no, $thread->order->order_no);
        self::assertSame(1, ProcessThread::query()->where('type', ProcessThreadType::WARRANTY)->count());
        self::assertSame(1, $thread->events()->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'WARRANTY_CYCLE_CREATED', 'subject_id' => $thread->id]);
    }

    public function test_completed_claim_one_allows_claim_two_without_overwriting_history(): void
    {
        [$order, $item] = $this->orderAndItem('repeat');
        $first = $this->createCycle($order, $item, 'cycle-1');
        $this->advanceManuallyToReceivedBack($first);
        $this->act($first, WarrantyAction::SEND_TO_CUSTOMER, $this->akOperator, 'cycle-1-final');
        $states = $first->events()->orderBy('id')->pluck('to_state')->all();
        $firstEventIds = $first->events()->pluck('id')->all();

        $second = $this->createCycle($order, $item->fresh(), 'cycle-2');

        self::assertSame(2, $second->warranty_cycle);
        self::assertSame(WarrantyState::SENT_TO_CUSTOMER->value, $first->fresh()->state);
        self::assertSame($states, $first->events()->orderBy('id')->pluck('to_state')->all());
        self::assertSame($firstEventIds, $first->events()->pluck('id')->all());
        self::assertSame($second->id, $item->fresh()->active_warranty_thread_id);
    }

    public function test_two_active_cycles_for_same_piece_are_rejected_but_two_items_are_independent(): void
    {
        [$order, $first] = $this->orderAndItem('two-items');
        $second = Item::factory()->create(['order_id' => $order->id, 'role_instance' => 2, 'current_location' => OrganizationCode::AUTOKLUBY]);
        $firstThread = $this->createCycle($order, $first, 'first-piece');

        try {
            $this->createCycle($order, $first->fresh(), 'competing-piece');
            self::fail('Expected the item reservation to reject a competing cycle.');
        } catch (WorkflowConflictException) {
            self::assertSame($firstThread->id, $first->fresh()->active_warranty_thread_id);
        }

        $secondThread = $this->createCycle($order, $second, 'second-piece');
        self::assertSame(1, $firstThread->warranty_cycle);
        self::assertSame(1, $secondThread->warranty_cycle);
        self::assertNotSame($firstThread->type_instance, $secondThread->type_instance);
    }

    public function test_stale_version_replay_and_skipped_transition_guards(): void
    {
        [$order, $item] = $this->orderAndItem('locking');
        $thread = $this->createCycle($order, $item, 'locking-create');
        $event = $this->act($thread, WarrantyAction::PREPARE_TO_POLAND, $this->akOperator, 'prepare');
        $replay = $this->workflow->execute($thread, new WarrantyCommand(
            WarrantyAction::PREPARE_TO_POLAND, $this->akOperator, 'prepare', [], 0,
        ));
        self::assertSame($event->id, $replay->id);

        foreach ([
            new WarrantyCommand(WarrantyAction::SEND_TO_POLAND, $this->akOperator, 'stale', [], 0),
            new WarrantyCommand(WarrantyAction::SEND_TO_CUSTOMER, $this->akOperator, 'skip', [], 1),
        ] as $command) {
            try {
                $this->workflow->execute($thread, $command);
                self::fail('Expected concurrency/transition conflict.');
            } catch (WorkflowConflictException) {
                self::assertSame(WarrantyState::READY_TO_PL->value, $thread->fresh()->state);
            }
        }
    }

    public function test_event_and_state_roll_back_atomically(): void
    {
        [$order, $item] = $this->orderAndItem('rollback');
        $thread = $this->createCycle($order, $item, 'rollback-create');
        $before = $thread->events()->count();
        ThreadEvent::creating(static function (): never {
            throw new RuntimeException('simulated event write failure');
        });

        try {
            $this->act($thread, WarrantyAction::PREPARE_TO_POLAND, $this->akOperator, 'rollback-action');
            self::fail('Expected simulated persistence failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated event write failure', $exception->getMessage());
        }

        self::assertSame(WarrantyState::RECEIVED_AK->value, $thread->fresh()->state);
        self::assertSame(0, $thread->fresh()->lock_version);
        self::assertSame($before, $thread->events()->count());
    }

    public function test_shipments_drive_both_directions_atomically_and_reject_wrong_direction_or_duplicate_reservation(): void
    {
        [$order, $item] = $this->orderAndItem('shipping');
        $thread = $this->createCycle($order, $item, 'shipping-create');
        $this->act($thread, WarrantyAction::PREPARE_TO_POLAND, $this->akOperator, 'ready-pl');

        $wrong = $this->createShipment(ShipmentDirection::PL_TO_AK, ShipmentCarrier::DPD, $this->plOperator, 'wrong');
        try {
            $this->addShipmentItem($wrong, $item, $thread, $this->plOperator, 'wrong-add');
            self::fail('Expected wrong shipment direction rejection.');
        } catch (WorkflowConflictException) {
            self::assertNull($item->fresh()->active_shipment_id);
        }

        $outbound = $this->createShipment(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, 'out');
        $this->addShipmentItem($outbound, $item, $thread, $this->akOperator, 'out-add');
        $duplicate = $this->createShipment(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, 'duplicate');
        try {
            $this->addShipmentItem($duplicate, $item, $thread, $this->akOperator, 'duplicate-add');
            self::fail('Expected duplicate active shipment rejection.');
        } catch (WorkflowConflictException) {
            self::assertSame($outbound->id, $item->fresh()->active_shipment_id);
        }
        $sendCommand = new ShipmentCommand($this->akOperator, 'out-send', ['tracking' => 'GLS-WARRANTY-1'], $outbound->fresh()->lock_version);
        $sent = $this->shipments->send($outbound, $sendCommand);
        $replayed = $this->shipments->send($outbound, $sendCommand);
        self::assertSame($sent->id, $replayed->id);
        self::assertSame(WarrantyState::SENT_TO_PL->value, $thread->fresh()->state);
        $this->deliverShipment($outbound, $this->plOperator, 'out-deliver');
        self::assertSame(WarrantyState::RECEIVED_PL->value, $thread->fresh()->state);
        self::assertSame(OrganizationCode::POLAND->value, $item->fresh()->current_location);

        $this->act($thread, WarrantyAction::COMPLETE_REPAIR, $this->plOperator, 'repair');
        $this->act($thread, WarrantyAction::PREPARE_TO_AUTOKLUBY, $this->plOperator, 'ready-ak');
        $inbound = $this->createShipment(ShipmentDirection::PL_TO_AK, ShipmentCarrier::DPD, $this->plOperator, 'in');
        $this->addShipmentItem($inbound, $item->fresh(), $thread, $this->plOperator, 'in-add');
        $this->sendShipment($inbound, $this->plOperator, 'DPD-WARRANTY-1', 'in-send');
        $this->deliverShipment($inbound, $this->akOperator, 'in-deliver');

        self::assertSame(WarrantyState::RECEIVED_AK_BACK->value, $thread->fresh()->state);
        self::assertSame(ShipmentStatus::DELIVERED, $inbound->fresh()->status);
        self::assertNotNull($thread->events()->whereNotNull('metadata')->get()->first(
            fn (ThreadEvent $event): bool => isset($event->metadata['shipment_id']),
        ));
    }

    public function test_customer_pickup_is_terminal_without_fake_tracking(): void
    {
        [$order, $item] = $this->orderAndItem('pickup');
        $thread = $this->createCycle($order, $item, 'pickup-create');
        $this->advanceManuallyToReceivedBack($thread);
        $shipmentCount = Shipment::query()->count();
        $this->act($thread, WarrantyAction::CONFIRM_CUSTOMER_PICKUP, $this->akOperator, 'pickup-final');

        self::assertSame(WarrantyState::PICKED_UP_BY_CUSTOMER->value, $thread->fresh()->state);
        self::assertNotNull($thread->fresh()->completed_at);
        self::assertSame($shipmentCount, Shipment::query()->count());
        self::assertNull($item->fresh()->active_warranty_thread_id);
    }

    public function test_roles_row_level_access_safe_404_and_polish_response_has_no_pii(): void
    {
        [$order, $item] = $this->orderAndItem('permissions');
        OrderCustomer::query()->create([
            'order_id' => $order->id, 'name' => 'TAJNY KLIENT', 'email' => 'secret@example.test', 'phone' => '+420999999999',
        ]);
        $thread = $this->createCycle($order, $item, 'permission-create');

        foreach ([$this->plOperator, $this->readOnly] as $actor) {
            try {
                $this->workflow->create($order, $item->fresh(), new WarrantyCommand(WarrantyAction::CREATE_CYCLE, $actor, 'forbidden-'.$actor->id));
                self::fail('Expected create authorization denial.');
            } catch (AuthorizationException) {
                self::assertSame(1, ProcessThread::query()->where('type', ProcessThreadType::WARRANTY)->count());
            }
        }

        try {
            $this->workflow->execute($thread, new WarrantyCommand(
                WarrantyAction::PREPARE_TO_POLAND, $this->readOnly, 'readonly-action', [], 0,
            ));
            self::fail('Expected READ_ONLY action denial.');
        } catch (AuthorizationException) {
            self::assertSame(WarrantyState::RECEIVED_AK->value, $thread->fresh()->state);
        }

        $foreign = $this->actingAs($this->plOperator)->postJson(route('orders.warranty.actions', [
            $order, $thread, WarrantyAction::CONFIRM_POLAND_RECEIPT->value,
        ]), ['idempotency_key' => 'foreign-url', 'expected_lock_version' => 0]);
        $foreign->assertNotFound();

        $this->act($thread, WarrantyAction::PREPARE_TO_POLAND, $this->akOperator, 'permission-ready');
        $this->act($thread, WarrantyAction::ADMIN_OVERRIDE, $this->akAdmin, 'permission-assign', [
            'target_state' => WarrantyState::SENT_TO_PL->value,
            'reason' => 'Testované auditované předání.',
        ]);
        $response = $this->actingAs($this->plOperator)->postJson(route('orders.warranty.actions', [
            $order, $thread, WarrantyAction::CONFIRM_POLAND_RECEIPT->value,
        ]), ['idempotency_key' => 'pl-receive', 'expected_lock_version' => 2]);
        $response->assertOk()->assertJsonMissing(['name' => 'TAJNY KLIENT']);
        $response->assertDontSee('secret@example.test')->assertDontSee('+420999999999');

        self::assertTrue(ProcessThread::query()->visibleTo($this->plOperator)->whereKey($thread->id)->exists());
        $this->assertDatabaseHas('audit_log', ['action' => 'WARRANTY_ADMIN_OVERRIDE', 'subject_id' => $thread->id]);
    }

    public function test_warranty_does_not_change_original_workflow_order_finance_or_discussion(): void
    {
        [$order, $item] = $this->orderAndItem('isolation');
        $original = ProcessThread::factory()->create([
            'order_id' => $order->id,
            'type' => ProcessThreadType::CUSTOM_REBUILD,
            'type_instance' => 1,
            'state' => 'RECEIVED_AK',
            'responsible_party' => ResponsibleParty::AUTOKLUBY,
            'responsible_organization_id' => $this->ak->id,
            'lock_version' => 7,
        ]);
        $snapshot = $order->only(['order_no', 'business_snapshot', 'closed_at']);
        $messageCount = $order->messages()->count();
        $financeCount = $order->financeEntries()->count();

        $thread = $this->createCycle($order, $item, 'isolation-create');
        $this->act($thread, WarrantyAction::PREPARE_TO_POLAND, $this->akOperator, 'isolation-ready');

        self::assertSame('RECEIVED_AK', $original->fresh()->state);
        self::assertSame(7, $original->fresh()->lock_version);
        self::assertSame($snapshot, $order->fresh()->only(['order_no', 'business_snapshot', 'closed_at']));
        self::assertSame($messageCount, $order->messages()->count());
        self::assertSame($financeCount, $order->financeEntries()->count());
    }

    private function orderAndItem(string $suffix): array
    {
        $order = Order::factory()->create(['order_no' => 'W-'.$suffix, 'source_order_id' => 'W-'.$suffix]);
        $item = Item::factory()->create(['order_id' => $order->id, 'current_location' => OrganizationCode::AUTOKLUBY]);

        return [$order, $item];
    }

    private function createCycle(Order $order, Item $item, string $key): ProcessThread
    {
        return $this->workflow->create($order, $item, new WarrantyCommand(WarrantyAction::CREATE_CYCLE, $this->akOperator, $key));
    }

    private function act(ProcessThread $thread, WarrantyAction $action, User $actor, string $key, array $data = []): ThreadEvent
    {
        $fresh = $thread->fresh();

        return $this->workflow->execute($fresh, new WarrantyCommand($action, $actor, $key, $data, $fresh->lock_version));
    }

    private function advanceManuallyToReceivedBack(ProcessThread $thread): void
    {
        $this->act($thread, WarrantyAction::PREPARE_TO_POLAND, $this->akOperator, 'advance-1-'.$thread->id);
        $this->act($thread, WarrantyAction::ADMIN_OVERRIDE, $this->akAdmin, 'advance-2-'.$thread->id, [
            'target_state' => WarrantyState::SENT_TO_PL->value, 'reason' => 'Test shipment boundary.',
        ]);
        $this->act($thread, WarrantyAction::CONFIRM_POLAND_RECEIPT, $this->plOperator, 'advance-3-'.$thread->id);
        $this->act($thread, WarrantyAction::COMPLETE_REPAIR, $this->plOperator, 'advance-4-'.$thread->id);
        $this->act($thread, WarrantyAction::PREPARE_TO_AUTOKLUBY, $this->plOperator, 'advance-5-'.$thread->id);
        $this->act($thread, WarrantyAction::ADMIN_OVERRIDE, $this->akAdmin, 'advance-6-'.$thread->id, [
            'target_state' => WarrantyState::SENT_TO_AK->value, 'reason' => 'Test shipment boundary.',
        ]);
        $this->act($thread, WarrantyAction::CONFIRM_AUTOKLUBY_RECEIPT, $this->akOperator, 'advance-7-'.$thread->id);
    }

    private function createShipment(ShipmentDirection $direction, ShipmentCarrier $carrier, User $actor, string $key): Shipment
    {
        return $this->shipments->create(new ShipmentCommand($actor, $key.'-create', [
            'direction' => $direction->value, 'carrier' => $carrier->value,
        ]));
    }

    private function addShipmentItem(Shipment $shipment, Item $item, ProcessThread $thread, User $actor, string $key): void
    {
        $this->shipments->addItem($shipment, $item, $thread, new ShipmentCommand($actor, $key, [], $shipment->fresh()->lock_version));
    }

    private function sendShipment(Shipment $shipment, User $actor, string $tracking, string $key): void
    {
        $this->shipments->send($shipment, new ShipmentCommand($actor, $key, ['tracking' => $tracking], $shipment->fresh()->lock_version));
    }

    private function deliverShipment(Shipment $shipment, User $actor, string $key): void
    {
        $this->shipments->confirmDelivery($shipment, new ShipmentCommand($actor, $key, [], $shipment->fresh()->lock_version));
    }
}
