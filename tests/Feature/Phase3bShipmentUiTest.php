<?php

namespace Tests\Feature;

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
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\Shipment;
use App\Models\ThreadEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase3bShipmentUiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $autokluby;

    private Organization $poland;

    private User $akOperator;

    private User $plOperator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autokluby = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $this->poland = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $this->akOperator = User::factory()->create(['organization_id' => $this->autokluby->id, 'role' => UserRole::AK_OPERATOR]);
        $this->plOperator = User::factory()->create(['organization_id' => $this->poland->id, 'role' => UserRole::PL_OPERATOR, 'preferred_locale' => 'pl']);
    }

    public function test_sender_can_create_and_both_participants_can_view_shipment(): void
    {
        $response = $this->actingAs($this->akOperator)->post(route('shipments.store'), [
            'direction' => ShipmentDirection::AK_TO_PL->value,
            'carrier' => ShipmentCarrier::GLS->value,
            'tracking' => '',
            'idempotency_key' => 'ui-create',
        ]);
        $shipment = Shipment::query()->sole();
        $response->assertRedirect(route('shipments.show', $shipment));
        $this->actingAs($this->akOperator)->get(route('shipments.show', $shipment))
            ->assertOk()->assertSee($shipment->public_number)->assertSee('Skenování kusů');
        $this->actingAs($this->plOperator)->get(route('shipments.show', $shipment))
            ->assertOk()->assertSee($shipment->public_number)->assertDontSee('Skanowanie części');
    }

    public function test_qr_url_adds_item_and_stale_duplicate_is_reported_safely(): void
    {
        [$item] = $this->outboundItem();
        $shipment = $this->shipment();
        $payload = [
            'qr_token' => route('items.qr', $item->qr_token),
            'idempotency_key' => 'ui-add',
            'expected_lock_version' => 0,
        ];
        $this->actingAs($this->akOperator)->post(route('shipments.items.store', $shipment), $payload)
            ->assertRedirect(route('shipments.show', $shipment));
        $this->assertDatabaseHas('shipment_items', ['shipment_id' => $shipment->id, 'item_id' => $item->id]);
        self::assertSame($shipment->id, $item->fresh()->active_shipment_id);

        $this->actingAs($this->akOperator)->post(route('shipments.items.store', $shipment), array_merge($payload, ['idempotency_key' => 'ui-add-stale']))
            ->assertSessionHasErrors('shipment');
        self::assertSame(1, $shipment->shipmentItems()->count());
    }

    public function test_http_send_and_receive_advance_whole_workflow(): void
    {
        [$item, $thread] = $this->outboundItem();
        $shipment = $this->shipment();
        $this->actingAs($this->akOperator)->post(route('shipments.items.store', $shipment), [
            'qr_token' => $item->qr_token, 'idempotency_key' => 'add-send', 'expected_lock_version' => 0,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->akOperator)->post(route('shipments.actions', [$shipment, ShipmentAction::SEND_SHIPMENT->value]), [
            'tracking' => 'GLS-UI-001', 'idempotency_key' => 'ui-send', 'expected_lock_version' => 1,
        ])->assertSessionHasNoErrors();
        self::assertSame(ShipmentStatus::SENT, $shipment->fresh()->status);
        self::assertSame(CustomRebuildState::SENT_TO_PL->value, $thread->fresh()->state);

        $this->actingAs($this->plOperator)->post(route('shipments.actions', [$shipment, ShipmentAction::CONFIRM_SHIPMENT_DELIVERY->value]), [
            'idempotency_key' => 'ui-deliver', 'expected_lock_version' => 2,
        ])->assertSessionHasNoErrors();
        self::assertSame(ShipmentStatus::DELIVERED, $shipment->fresh()->status);
        self::assertSame(CustomRebuildState::RECEIVED_PL_REPAIR->value, $thread->fresh()->state);
        self::assertNull($item->fresh()->active_shipment_id);
    }

    private function shipment(): Shipment
    {
        return Shipment::query()->create([
            'direction' => ShipmentDirection::AK_TO_PL,
            'carrier' => ShipmentCarrier::GLS,
            'status' => ShipmentStatus::PREPARED,
            'sender_organization_id' => $this->autokluby->id,
            'recipient_organization_id' => $this->poland->id,
            'created_by' => $this->akOperator->id,
        ]);
    }

    /** @return array{Item, ProcessThread} */
    private function outboundItem(): array
    {
        $order = Order::factory()->create();
        $item = Item::factory()->create([
            'order_id' => $order->id,
            'role' => ItemRole::CUSTOMER_ITEM,
            'current_location' => OrganizationCode::AUTOKLUBY->value,
        ]);
        $thread = ProcessThread::factory()->create([
            'order_id' => $order->id,
            'type' => ProcessThreadType::CUSTOM_REBUILD,
            'state' => CustomRebuildState::RECEIVED_AK->value,
            'responsible_party' => ResponsibleParty::AUTOKLUBY,
            'responsible_organization_id' => $this->autokluby->id,
        ]);
        ThreadEvent::query()->create([
            'process_thread_id' => $thread->id,
            'order_id' => $order->id,
            'action' => CustomRebuildAction::CONFIRM_AK_RECEIPT->value,
            'to_state' => CustomRebuildState::RECEIVED_AK->value,
            'actor_type' => OrganizationCode::AUTOKLUBY->value,
            'responsible_organization_id' => $this->autokluby->id,
        ]);

        return [$item, $thread];
    }
}
