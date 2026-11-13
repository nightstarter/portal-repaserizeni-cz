<?php

namespace Tests\Feature;

use App\Application\Shipments\ShipmentCommand;
use App\Application\Shipments\ShipmentService;
use App\Enums\CustomRebuildAction;
use App\Enums\CustomRebuildState;
use App\Enums\ItemRole;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\ShipmentAction;
use App\Enums\ShipmentCarrier;
use App\Enums\ShipmentDirection;
use App\Enums\ShipmentStatus;
use App\Enums\StockRebuildAction;
use App\Enums\StockRebuildState;
use App\Enums\UserRole;
use App\Exceptions\WorkflowConflictException;
use App\Models\Item;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ThreadEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShipmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $autokluby;

    private Organization $poland;

    private User $akOperator;

    private User $akAdmin;

    private User $plOperator;

    private ShipmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autokluby = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $this->poland = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $this->akOperator = User::factory()->create(['organization_id' => $this->autokluby->id, 'role' => UserRole::AK_OPERATOR]);
        $this->akAdmin = User::factory()->create(['organization_id' => $this->autokluby->id, 'role' => UserRole::AK_ADMIN]);
        $this->plOperator = User::factory()->create(['organization_id' => $this->poland->id, 'role' => UserRole::PL_OPERATOR]);
        $this->service = app(ShipmentService::class);
    }

    public function test_allowed_direction_and_carrier_matrix_creates_empty_prepared_shipments(): void
    {
        $cases = [
            [ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator],
            [ShipmentDirection::AK_TO_PL, ShipmentCarrier::SVOZ_AUTOKLUBY, $this->akOperator],
            [ShipmentDirection::PL_TO_AK, ShipmentCarrier::DPD, $this->plOperator],
            [ShipmentDirection::PL_TO_AK, ShipmentCarrier::SVOZ_AUTOKLUBY, $this->plOperator],
        ];

        foreach ($cases as $index => [$direction, $carrier, $actor]) {
            $shipment = $this->createShipment($direction, $carrier, $actor, 'matrix-'.$index);
            self::assertSame(ShipmentStatus::PREPARED, $shipment->status);
            self::assertSame($direction->sender(), $shipment->senderOrganization->code);
            self::assertSame($direction->recipient(), $shipment->recipientOrganization->code);
            self::assertSame(0, $shipment->shipmentItems()->count());
            self::assertMatchesRegularExpression('/^SHP-[A-Z0-9]{26}$/', $shipment->public_number);
        }

        self::assertSame(4, Shipment::query()->count());
        self::assertSame(4, Shipment::query()->pluck('public_number')->unique()->count());
    }

    public function test_invalid_direction_carrier_and_wrong_creator_organization_are_rejected(): void
    {
        foreach ([
            [ShipmentDirection::AK_TO_PL, ShipmentCarrier::DPD, $this->akOperator],
            [ShipmentDirection::PL_TO_AK, ShipmentCarrier::GLS, $this->plOperator],
        ] as $index => [$direction, $carrier, $actor]) {
            try {
                $this->createShipment($direction, $carrier, $actor, 'invalid-carrier-'.$index);
                self::fail('Expected invalid carrier validation.');
            } catch (ValidationException) {
                self::assertSame(0, Shipment::query()->count());
            }
        }

        $this->expectException(AuthorizationException::class);
        $this->createShipment(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->plOperator, 'wrong-org');
    }

    public function test_ak_admin_creation_override_requires_reason_and_is_audited(): void
    {
        try {
            $this->createShipment(ShipmentDirection::PL_TO_AK, ShipmentCarrier::DPD, $this->akAdmin, 'override-missing');
            self::fail('Expected missing override reason.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reason', $exception->errors());
        }

        $shipment = $this->service->create(new ShipmentCommand($this->akAdmin, 'override-ok', [
            'direction' => ShipmentDirection::PL_TO_AK->value,
            'carrier' => ShipmentCarrier::DPD->value,
            'reason' => 'Nouzová příprava zásilky za polskou směnu.',
        ]));

        self::assertSame(ShipmentStatus::PREPARED, $shipment->status);
        $this->assertDatabaseHas('shipment_events', ['shipment_id' => $shipment->id, 'reason' => 'Nouzová příprava zásilky za polskou směnu.']);
        $this->assertDatabaseHas('audit_log', ['subject_type' => Shipment::class, 'subject_id' => $shipment->id, 'action' => 'SHIPMENT_ADMIN_OVERRIDE_CREATED']);
    }

    public function test_gls_and_dpd_require_tracking_and_svoz_rejects_tracking(): void
    {
        [$akItem, $akThread] = $this->customOutbound('track-gls');
        $gls = $this->createShipment(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, 'gls');
        $this->add($gls, $akItem, $akThread, $this->akOperator, 'gls-add');
        $this->assertSendValidation($gls, $this->akOperator, null, 'tracking');

        [$plItem, $plThread] = $this->customInbound('track-dpd');
        $dpd = $this->createShipment(ShipmentDirection::PL_TO_AK, ShipmentCarrier::DPD, $this->plOperator, 'dpd');
        $this->add($dpd, $plItem, $plThread, $this->plOperator, 'dpd-add');
        $this->assertSendValidation($dpd, $this->plOperator, null, 'tracking');

        [$svozItem, $svozThread] = $this->customOutbound('track-svoz');
        $svoz = $this->createShipment(ShipmentDirection::AK_TO_PL, ShipmentCarrier::SVOZ_AUTOKLUBY, $this->akOperator, 'svoz');
        $this->add($svoz, $svozItem, $svozThread, $this->akOperator, 'svoz-add');
        $this->assertSendValidation($svoz, $this->akOperator, 'FAKE-TRACKING', 'tracking');
    }

    public function test_all_four_transport_combinations_send_and_deliver(): void
    {
        [$akGlsItem, $akGlsThread] = $this->customOutbound('ak-gls');
        $akGls = $this->preparedWithItem(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, $akGlsItem, $akGlsThread, 'ak-gls');
        $this->send($akGls, $this->akOperator, 'GLS-3A-001', 'ak-gls-send');
        $this->deliver($akGls, $this->plOperator, 'ak-gls-deliver');
        self::assertSame(CustomRebuildState::RECEIVED_PL_REPAIR->value, $akGlsThread->fresh()->state);

        [$akSvozItem, $akSvozThread] = $this->customOutbound('ak-svoz');
        $akSvoz = $this->preparedWithItem(ShipmentDirection::AK_TO_PL, ShipmentCarrier::SVOZ_AUTOKLUBY, $this->akOperator, $akSvozItem, $akSvozThread, 'ak-svoz');
        $this->send($akSvoz, $this->akOperator, null, 'ak-svoz-send');
        $this->deliver($akSvoz, $this->plOperator, 'ak-svoz-deliver');

        [$plDpdItem, $plDpdThread] = $this->customInbound('pl-dpd');
        $plDpd = $this->preparedWithItem(ShipmentDirection::PL_TO_AK, ShipmentCarrier::DPD, $this->plOperator, $plDpdItem, $plDpdThread, 'pl-dpd');
        $this->send($plDpd, $this->plOperator, 'DPD-3A-001', 'pl-dpd-send');
        $this->deliver($plDpd, $this->akOperator, 'pl-dpd-deliver');
        self::assertSame(CustomRebuildState::RECEIVED_AK->value, $plDpdThread->fresh()->state);

        [$plSvozItem, $plSvozThread] = $this->stockInbound('pl-svoz', ProcessThreadType::STOCK_REPLENISHMENT);
        $plSvoz = $this->preparedWithItem(ShipmentDirection::PL_TO_AK, ShipmentCarrier::SVOZ_AUTOKLUBY, $this->plOperator, $plSvozItem, $plSvozThread, 'pl-svoz');
        $this->send($plSvoz, $this->plOperator, null, 'pl-svoz-send');
        $this->deliver($plSvoz, $this->akOperator, 'pl-svoz-deliver');
        self::assertSame(StockRebuildState::RECEIVED_AK->value, $plSvozThread->fresh()->state);
        self::assertNotNull($plSvozThread->fresh()->completed_at);

        foreach ([$akGls, $akSvoz, $plDpd, $plSvoz] as $shipment) {
            self::assertSame(ShipmentStatus::DELIVERED, $shipment->fresh()->status);
            self::assertNull($shipment->items()->sole()->active_shipment_id);
        }
    }

    public function test_multiple_orders_are_sent_atomically_in_one_shipment(): void
    {
        [$firstItem, $firstThread] = $this->customOutbound('batch-one');
        [$secondItem, $secondThread] = $this->customOutbound('batch-two');
        $shipment = $this->createShipment(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, 'batch');
        $this->add($shipment, $firstItem, $firstThread, $this->akOperator, 'batch-add-1');
        $this->add($shipment, $secondItem, $secondThread, $this->akOperator, 'batch-add-2');

        $event = $this->send($shipment, $this->akOperator, 'GLS-MULTI-001', 'batch-send');

        self::assertSame(ShipmentStatus::SENT, $shipment->fresh()->status);
        self::assertSame(CustomRebuildState::SENT_TO_PL->value, $firstThread->fresh()->state);
        self::assertSame(CustomRebuildState::SENT_TO_PL->value, $secondThread->fresh()->state);
        self::assertCount(2, $event->metadata['items']);
        self::assertSame(2, $shipment->shipmentItems()->count());
    }

    public function test_hundred_item_batch_prevalidation_is_bounded_and_failure_writes_nothing(): void
    {
        $shipment = $this->createShipment(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, 'batch-100');
        $threads = collect();

        foreach (range(1, 100) as $index) {
            [$item, $thread] = $this->customOutbound('batch-100-'.$index);
            $this->add($shipment, $item, $thread, $this->akOperator, 'batch-100-add-'.$index);
            $threads->push($thread);
        }

        $threads->last()->update(['state' => CustomRebuildState::WAITING_CUSTOMER_ITEM->value]);
        $selects = [];
        DB::listen(static function ($query) use (&$selects): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $selects[] = $query->sql;
            }
        });

        try {
            $this->send($shipment, $this->akOperator, 'GLS-BATCH-100', 'batch-100-send');
            self::fail('Expected whole-batch validation failure.');
        } catch (WorkflowConflictException) {
            self::assertLessThanOrEqual(10, count($selects), 'Batch prevalidation must load orders and events in bounded queries.');
            self::assertSame(ShipmentStatus::PREPARED, $shipment->fresh()->status);
            self::assertSame(100, $shipment->shipmentItems()->count());
            self::assertSame(99, $threads->filter(
                fn (ProcessThread $thread): bool => $thread->fresh()->state === CustomRebuildState::RECEIVED_AK->value,
            )->count());
            self::assertFalse($shipment->events()->where('action', ShipmentAction::SEND_SHIPMENT)->exists());
            self::assertSame(0, ThreadEvent::query()->where('action', CustomRebuildAction::SEND_TO_POLAND)->count());
        }
    }

    public function test_duplicate_item_in_same_or_second_active_shipment_is_rejected(): void
    {
        [$item, $thread] = $this->customOutbound('duplicate-item');
        $first = $this->createShipment(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, 'dup-first');
        $this->add($first, $item, $thread, $this->akOperator, 'dup-add-first');

        try {
            $this->add($first, $item, $thread, $this->akOperator, 'dup-same');
            self::fail('Expected duplicate item conflict.');
        } catch (WorkflowConflictException) {
            self::assertSame(1, $first->shipmentItems()->count());
        }

        $second = $this->createShipment(ShipmentDirection::AK_TO_PL, ShipmentCarrier::SVOZ_AUTOKLUBY, $this->akOperator, 'dup-second');
        try {
            $this->add($second, $item, $thread, $this->akOperator, 'dup-other');
            self::fail('Expected active reservation conflict.');
        } catch (WorkflowConflictException) {
            self::assertSame($first->id, $item->fresh()->active_shipment_id);
            self::assertSame(0, $second->shipmentItems()->count());
        }
    }

    public function test_wrong_direction_state_organization_and_unrelated_thread_are_rejected_without_partial_write(): void
    {
        [$plItem, $plThread] = $this->customInbound('wrong-direction');
        $akShipment = $this->createShipment(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, 'wrong-direction');
        $this->expectAddConflict($akShipment, $plItem, $plThread, $this->akOperator, 'wrong-direction-add');

        [$waitingItem, $waitingThread] = $this->customOutbound('wrong-state');
        $waitingThread->update(['state' => CustomRebuildState::WAITING_CUSTOMER_ITEM->value]);
        $waitingShipment = $this->createShipment(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, 'wrong-state');
        $this->expectAddConflict($waitingShipment, $waitingItem, $waitingThread, $this->akOperator, 'wrong-state-add');

        [$foreignItem, $foreignThread] = $this->customOutbound('wrong-org');
        $foreignShipment = $this->createShipment(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, 'wrong-org');
        try {
            $this->add($foreignShipment, $foreignItem, $foreignThread, $this->plOperator, 'wrong-org-add');
            self::fail('Expected organization rejection.');
        } catch (AuthorizationException) {
            self::assertNull($foreignItem->fresh()->active_shipment_id);
        }

        [$item, $thread] = $this->customOutbound('unrelated-a');
        [, $unrelatedThread] = $this->customOutbound('unrelated-b');
        $unrelatedShipment = $this->createShipment(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, 'unrelated');
        $this->expectAddConflict($unrelatedShipment, $item, $unrelatedThread, $this->akOperator, 'unrelated-add');
        self::assertSame(CustomRebuildState::RECEIVED_AK->value, $thread->fresh()->state);
    }

    public function test_failure_of_one_item_rolls_back_whole_batch_dispatch(): void
    {
        [$firstItem, $firstThread] = $this->customOutbound('rollback-one');
        [$secondItem, $secondThread] = $this->customOutbound('rollback-two');
        $shipment = $this->createShipment(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, 'rollback');
        $this->add($shipment, $firstItem, $firstThread, $this->akOperator, 'rollback-add-1');
        $this->add($shipment, $secondItem, $secondThread, $this->akOperator, 'rollback-add-2');
        $secondThread->update(['state' => CustomRebuildState::WAITING_CUSTOMER_ITEM->value]);

        try {
            $this->send($shipment, $this->akOperator, 'GLS-ROLLBACK', 'rollback-send');
            self::fail('Expected batch rollback.');
        } catch (WorkflowConflictException) {
            self::assertSame(ShipmentStatus::PREPARED, $shipment->fresh()->status);
            self::assertSame(CustomRebuildState::RECEIVED_AK->value, $firstThread->fresh()->state);
            self::assertSame(CustomRebuildState::WAITING_CUSTOMER_ITEM->value, $secondThread->fresh()->state);
            self::assertFalse(ShipmentEvent::query()->where('action', ShipmentAction::SEND_SHIPMENT)->where('shipment_id', $shipment->id)->exists());
        }
    }

    public function test_batch_receipt_maps_custom_and_stock_actions_atomically(): void
    {
        [$customItem, $customThread] = $this->customInbound('receipt-custom');
        [$stockItem, $stockThread] = $this->stockInbound('receipt-stock', ProcessThreadType::STOCK_REQUEST);
        $shipment = $this->createShipment(ShipmentDirection::PL_TO_AK, ShipmentCarrier::DPD, $this->plOperator, 'receipt-batch');
        $this->add($shipment, $customItem, $customThread, $this->plOperator, 'receipt-add-custom');
        $this->add($shipment, $stockItem, $stockThread, $this->plOperator, 'receipt-add-stock');
        $this->send($shipment, $this->plOperator, 'DPD-RECEIPT-001', 'receipt-send');

        $event = $this->deliver($shipment, $this->akOperator, 'receipt-deliver');

        self::assertSame(ShipmentStatus::DELIVERED, $shipment->fresh()->status);
        self::assertSame(CustomRebuildState::RECEIVED_AK->value, $customThread->fresh()->state);
        self::assertSame(StockRebuildState::RECEIVED_AK->value, $stockThread->fresh()->state);
        self::assertSame(OrganizationCode::AUTOKLUBY->value, $customItem->fresh()->current_location);
        self::assertSame(OrganizationCode::AUTOKLUBY->value, $stockItem->fresh()->current_location);
        self::assertNull($customItem->fresh()->active_shipment_id);
        self::assertNull($stockItem->fresh()->active_shipment_id);
        self::assertCount(2, $event->metadata['items']);
        $this->assertDatabaseHas('thread_events', ['process_thread_id' => $customThread->id, 'action' => CustomRebuildAction::CONFIRM_AK_RETURN_RECEIPT->value]);
        $this->assertDatabaseHas('thread_events', ['process_thread_id' => $stockThread->id, 'action' => StockRebuildAction::CONFIRM_AK_STOCK_RECEIPT->value]);
    }

    public function test_invalid_single_receipt_rolls_back_every_item_and_reservation(): void
    {
        [$customItem, $customThread] = $this->customInbound('receipt-rollback-custom');
        [$stockItem, $stockThread] = $this->stockInbound('receipt-rollback-stock', ProcessThreadType::STOCK_REQUEST);
        $shipment = $this->createShipment(ShipmentDirection::PL_TO_AK, ShipmentCarrier::DPD, $this->plOperator, 'receipt-rollback');
        $this->add($shipment, $customItem, $customThread, $this->plOperator, 'receipt-rollback-add-custom');
        $this->add($shipment, $stockItem, $stockThread, $this->plOperator, 'receipt-rollback-add-stock');
        $this->send($shipment, $this->plOperator, 'DPD-RECEIPT-ROLLBACK', 'receipt-rollback-send');
        $stockThread->update(['state' => StockRebuildState::WAITING_PL_STOCK->value]);

        try {
            $this->deliver($shipment, $this->akOperator, 'receipt-rollback-deliver');
            self::fail('Expected atomic receipt rollback.');
        } catch (WorkflowConflictException) {
            self::assertSame(ShipmentStatus::SENT, $shipment->fresh()->status);
            self::assertSame(CustomRebuildState::SENT_TO_AK->value, $customThread->fresh()->state);
            self::assertSame(StockRebuildState::WAITING_PL_STOCK->value, $stockThread->fresh()->state);
            self::assertSame($shipment->id, $customItem->fresh()->active_shipment_id);
            self::assertSame($shipment->id, $stockItem->fresh()->active_shipment_id);
            self::assertFalse($shipment->events()->where('action', ShipmentAction::CONFIRM_SHIPMENT_DELIVERY)->exists());
        }
    }

    public function test_carrier_tracking_is_unique_and_conflict_does_not_send_second_shipment(): void
    {
        [$firstItem, $firstThread] = $this->customOutbound('tracking-unique-one');
        $first = $this->preparedWithItem(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, $firstItem, $firstThread, 'tracking-unique-one');
        $this->send($first, $this->akOperator, 'GLS-UNIQUE-3A', 'tracking-unique-send-one');

        [$secondItem, $secondThread] = $this->customOutbound('tracking-unique-two');
        $second = $this->preparedWithItem(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, $secondItem, $secondThread, 'tracking-unique-two');
        try {
            $this->send($second, $this->akOperator, 'GLS-UNIQUE-3A', 'tracking-unique-send-two');
            self::fail('Expected duplicate carrier/tracking conflict.');
        } catch (WorkflowConflictException) {
            self::assertSame(ShipmentStatus::PREPARED, $second->fresh()->status);
            self::assertSame(CustomRebuildState::RECEIVED_AK->value, $secondThread->fresh()->state);
        }
    }

    public function test_transport_error_requires_description_keeps_reservation_and_can_later_deliver(): void
    {
        [$item, $thread] = $this->customOutbound('transport-error');
        $shipment = $this->preparedWithItem(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, $item, $thread, 'transport-error');
        $this->send($shipment, $this->akOperator, 'GLS-ERROR-001', 'transport-send');

        try {
            $this->service->markTransportError($shipment, new ShipmentCommand($this->plOperator, 'error-empty', ['description' => '  '], $shipment->fresh()->lock_version));
            self::fail('Expected description validation.');
        } catch (ValidationException) {
            self::assertSame(ShipmentStatus::SENT, $shipment->fresh()->status);
        }

        $this->service->markTransportError($shipment, new ShipmentCommand(
            $this->plOperator,
            'error-valid',
            ['description' => 'Balík byl zadržen na depu.'],
            $shipment->fresh()->lock_version,
        ));
        self::assertSame(ShipmentStatus::TRANSPORT_ERROR, $shipment->fresh()->status);
        self::assertSame($shipment->id, $item->fresh()->active_shipment_id);
        self::assertSame(CustomRebuildState::SENT_TO_PL->value, $thread->fresh()->state);

        $this->deliver($shipment, $this->plOperator, 'error-later-delivery');
        self::assertSame(ShipmentStatus::DELIVERED, $shipment->fresh()->status);
        self::assertNull($item->fresh()->active_shipment_id);
        self::assertSame(CustomRebuildState::RECEIVED_PL_REPAIR->value, $thread->fresh()->state);
    }

    public function test_duplicate_idempotency_and_stale_concurrent_add_send_are_safe(): void
    {
        [$item, $thread] = $this->customOutbound('idempotency');
        $shipment = $this->createShipment(ShipmentDirection::AK_TO_PL, ShipmentCarrier::GLS, $this->akOperator, 'idempotency');
        $command = new ShipmentCommand($this->akOperator, 'same-add', [], 0);
        $first = $this->service->addItem($shipment, $item, $thread, $command);
        $second = $this->service->addItem($shipment, $item, $thread, $command);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, $shipment->shipmentItems()->count());
        self::assertSame(1, $shipment->events()->where('action', ShipmentAction::ADD_SHIPMENT_ITEM)->count());

        try {
            $this->service->send($shipment, new ShipmentCommand($this->akOperator, 'stale-send', ['tracking' => 'GLS-STALE'], 0));
            self::fail('Expected stale concurrent command.');
        } catch (WorkflowConflictException) {
            self::assertSame(ShipmentStatus::PREPARED, $shipment->fresh()->status);
            self::assertSame(CustomRebuildState::RECEIVED_AK->value, $thread->fresh()->state);
        }

        $sent = $this->send($shipment, $this->akOperator, 'GLS-CURRENT', 'current-send');
        $replayed = $this->service->send($shipment, new ShipmentCommand(
            $this->akOperator,
            'current-send',
            ['tracking' => 'GLS-CURRENT'],
            $sent->shipment->lock_version - 1,
        ));
        self::assertSame($sent->id, $replayed->id);
        self::assertSame(1, $thread->events()->where('action', CustomRebuildAction::SEND_TO_POLAND)->count());

        try {
            $this->service->send($shipment, new ShipmentCommand(
                $this->akOperator,
                'current-send',
                ['tracking' => 'DIFFERENT-PAYLOAD'],
                $sent->shipment->lock_version - 1,
            ));
            self::fail('Expected deterministic idempotency conflict.');
        } catch (WorkflowConflictException) {
            self::assertSame(1, $shipment->events()->where('action', ShipmentAction::SEND_SHIPMENT)->count());
        }
    }

    private function createShipment(ShipmentDirection $direction, ShipmentCarrier $carrier, User $actor, string $key): Shipment
    {
        return $this->service->create(new ShipmentCommand($actor, 'create-'.$key, [
            'direction' => $direction->value,
            'carrier' => $carrier->value,
        ]));
    }

    private function preparedWithItem(
        ShipmentDirection $direction,
        ShipmentCarrier $carrier,
        User $actor,
        Item $item,
        ProcessThread $thread,
        string $key,
    ): Shipment {
        $shipment = $this->createShipment($direction, $carrier, $actor, $key);
        $this->add($shipment, $item, $thread, $actor, $key.'-add');

        return $shipment;
    }

    private function add(Shipment $shipment, Item $item, ProcessThread $thread, User $actor, string $key): ShipmentEvent
    {
        return $this->service->addItem($shipment, $item, $thread, new ShipmentCommand(
            $actor,
            $key,
            [],
            $shipment->fresh()->lock_version,
        ));
    }

    private function send(Shipment $shipment, User $actor, ?string $tracking, string $key): ShipmentEvent
    {
        return $this->service->send($shipment, new ShipmentCommand(
            $actor,
            $key,
            $tracking === null ? [] : ['tracking' => $tracking],
            $shipment->fresh()->lock_version,
        ));
    }

    private function deliver(Shipment $shipment, User $actor, string $key): ShipmentEvent
    {
        return $this->service->confirmDelivery($shipment, new ShipmentCommand(
            $actor,
            $key,
            [],
            $shipment->fresh()->lock_version,
        ));
    }

    private function assertSendValidation(Shipment $shipment, User $actor, ?string $tracking, string $field): void
    {
        try {
            $this->send($shipment, $actor, $tracking, 'invalid-send-'.$shipment->id);
            self::fail('Expected send validation failure.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors());
            self::assertSame(ShipmentStatus::PREPARED, $shipment->fresh()->status);
        }
    }

    private function expectAddConflict(Shipment $shipment, Item $item, ProcessThread $thread, User $actor, string $key): void
    {
        try {
            $this->add($shipment, $item, $thread, $actor, $key);
            self::fail('Expected shipment item conflict.');
        } catch (WorkflowConflictException|AuthorizationException) {
            self::assertNull($item->fresh()->active_shipment_id);
            self::assertSame(0, $shipment->shipmentItems()->count());
        }
    }

    /** @return array{0: Item, 1: ProcessThread} */
    private function customOutbound(string $suffix): array
    {
        return $this->customPair($suffix, CustomRebuildState::RECEIVED_AK, OrganizationCode::AUTOKLUBY, CustomRebuildAction::CONFIRM_AK_RECEIPT);
    }

    /** @return array{0: Item, 1: ProcessThread} */
    private function customInbound(string $suffix): array
    {
        return $this->customPair($suffix, CustomRebuildState::REPAIRED_PL, OrganizationCode::POLAND, CustomRebuildAction::MARK_REPAIRED_PL);
    }

    /** @return array{0: Item, 1: ProcessThread} */
    private function customPair(
        string $suffix,
        CustomRebuildState $state,
        OrganizationCode $location,
        CustomRebuildAction $entryAction,
    ): array {
        $order = Order::factory()->create(['order_no' => 'CR-'.$suffix, 'source_order_id' => 'CR-'.$suffix]);
        $responsible = $location === OrganizationCode::AUTOKLUBY ? $this->autokluby : $this->poland;
        $thread = ProcessThread::factory()->create([
            'order_id' => $order->id,
            'type' => ProcessThreadType::CUSTOM_REBUILD,
            'state' => $state->value,
            'responsible_party' => $location === OrganizationCode::AUTOKLUBY ? ResponsibleParty::AUTOKLUBY : ResponsibleParty::POLAND,
            'responsible_organization_id' => $responsible->id,
        ]);
        ThreadEvent::query()->create([
            'process_thread_id' => $thread->id,
            'order_id' => $order->id,
            'action' => $entryAction->value,
            'to_state' => $state->value,
            'actor_type' => $location->value,
            'responsible_organization_id' => $responsible->id,
        ]);
        $item = Item::factory()->create([
            'order_id' => $order->id,
            'role' => ItemRole::CUSTOMER_ITEM,
            'current_location' => $location->value,
        ]);

        return [$item, $thread];
    }

    /** @return array{0: Item, 1: ProcessThread} */
    private function stockInbound(string $suffix, ProcessThreadType $type): array
    {
        $order = Order::factory()->stockRebuild()->create(['order_no' => 'SR-'.$suffix, 'source_order_id' => 'SR-'.$suffix]);
        $thread = ProcessThread::factory()->create([
            'order_id' => $order->id,
            'type' => $type,
            'state' => StockRebuildState::PL_STOCK_AVAILABLE->value,
            'responsible_party' => ResponsibleParty::POLAND,
            'responsible_organization_id' => $this->poland->id,
        ]);
        ThreadEvent::query()->create([
            'process_thread_id' => $thread->id,
            'order_id' => $order->id,
            'action' => StockRebuildAction::CONFIRM_PL_STOCK_AVAILABLE->value,
            'to_state' => StockRebuildState::PL_STOCK_AVAILABLE->value,
            'actor_type' => OrganizationCode::POLAND->value,
            'responsible_organization_id' => $this->poland->id,
        ]);
        $item = Item::factory()->create([
            'order_id' => $order->id,
            'role' => $type === ProcessThreadType::STOCK_REQUEST ? ItemRole::STOCK_ITEM : ItemRole::STOCK_REPLACEMENT,
            'current_location' => OrganizationCode::POLAND->value,
        ]);

        return [$item, $thread];
    }
}
