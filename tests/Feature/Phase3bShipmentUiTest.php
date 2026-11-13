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

    public function test_qr_url_and_token_add_items_and_duplicate_is_reported_safely(): void
    {
        [$urlItem] = $this->outboundItem();
        [$tokenItem] = $this->outboundItem();
        $shipment = $this->shipment();
        $this->actingAs($this->akOperator)->post(route('shipments.items.store', $shipment), [
            'qr_token' => route('items.qr', $urlItem->qr_token),
            'idempotency_key' => 'ui-add-url',
            'expected_lock_version' => 0,
        ])
            ->assertRedirect(route('shipments.show', $shipment));
        $this->actingAs($this->akOperator)->post(route('shipments.items.store', $shipment), [
            'qr_token' => $tokenItem->qr_token,
            'idempotency_key' => 'ui-add-token',
            'expected_lock_version' => 1,
        ])->assertRedirect(route('shipments.show', $shipment));

        $this->assertDatabaseHas('shipment_items', ['shipment_id' => $shipment->id, 'item_id' => $urlItem->id]);
        $this->assertDatabaseHas('shipment_items', ['shipment_id' => $shipment->id, 'item_id' => $tokenItem->id]);
        self::assertSame($shipment->id, $urlItem->fresh()->active_shipment_id);
        self::assertSame($shipment->id, $tokenItem->fresh()->active_shipment_id);

        $this->actingAs($this->akOperator)->post(route('shipments.items.store', $shipment), [
            'qr_token' => $tokenItem->qr_token,
            'idempotency_key' => 'ui-add-duplicate',
            'expected_lock_version' => 2,
        ])
            ->assertSessionHasErrors('shipment');
        self::assertSame(2, $shipment->shipmentItems()->count());
    }

    public function test_http_send_and_receive_advance_whole_workflow(): void
    {
        [$item, $thread] = $this->outboundItem();
        $shipment = $this->shipment();
        $this->actingAs($this->akOperator)->post(route('shipments.items.store', $shipment), [
            'qr_token' => $item->qr_token, 'idempotency_key' => 'add-send', 'expected_lock_version' => 0,
        ])->assertSessionHasNoErrors();

        $sendPayload = [
            'tracking' => 'GLS-UI-001', 'idempotency_key' => 'ui-send', 'expected_lock_version' => 1,
        ];
        $sendRoute = route('shipments.actions', [$shipment, ShipmentAction::SEND_SHIPMENT->value]);
        $this->actingAs($this->akOperator)->post($sendRoute, $sendPayload)->assertSessionHasNoErrors();
        $this->actingAs($this->akOperator)->post($sendRoute, $sendPayload)->assertSessionHasNoErrors();
        self::assertSame(ShipmentStatus::SENT, $shipment->fresh()->status);
        self::assertSame(CustomRebuildState::SENT_TO_PL->value, $thread->fresh()->state);
        self::assertSame(1, $shipment->events()->where('action', ShipmentAction::SEND_SHIPMENT)->count());

        $this->actingAs($this->plOperator)->post(route('shipments.actions', [$shipment, ShipmentAction::CONFIRM_SHIPMENT_DELIVERY->value]), [
            'idempotency_key' => 'ui-deliver', 'expected_lock_version' => 2,
        ])->assertSessionHasNoErrors();
        self::assertSame(ShipmentStatus::DELIVERED, $shipment->fresh()->status);
        self::assertSame(CustomRebuildState::RECEIVED_PL_REPAIR->value, $thread->fresh()->state);
        self::assertNull($item->fresh()->active_shipment_id);
    }

    public function test_svoz_ui_never_presents_a_fake_tracking_field(): void
    {
        $shipment = Shipment::query()->create([
            'direction' => ShipmentDirection::AK_TO_PL,
            'carrier' => ShipmentCarrier::SVOZ_AUTOKLUBY,
            'status' => ShipmentStatus::PREPARED,
            'sender_organization_id' => $this->autokluby->id,
            'recipient_organization_id' => $this->poland->id,
            'created_by' => $this->akOperator->id,
        ]);

        $this->actingAs($this->akOperator)->get(route('shipments.show', $shipment))
            ->assertOk()
            ->assertSee('Svoz AUTOKLUBY')
            ->assertDontSee('<dt>Tracking</dt>', false)
            ->assertDontSee('name="tracking"', false);

        $this->actingAs($this->akOperator)->get(route('shipments.create'))
            ->assertOk()
            ->assertSee('data-directions="AK_TO_PL PL_TO_AK"', false)
            ->assertSee('trackingField.hidden = withoutTracking', false)
            ->assertSee('tracking.disabled = withoutTracking', false);
    }

    public function test_invalid_wrong_direction_and_unauthorized_qr_requests_are_rejected_server_side(): void
    {
        $shipment = $this->shipment();
        $this->actingAs($this->akOperator)->post(route('shipments.items.store', $shipment), [
            'qr_token' => 'not-a-real-token', 'idempotency_key' => 'invalid-token', 'expected_lock_version' => 0,
        ])->assertSessionHasErrors('qr_token');

        $order = Order::factory()->create();
        $foreignItem = Item::factory()->create([
            'order_id' => $order->id,
            'role' => ItemRole::CUSTOMER_ITEM,
            'current_location' => OrganizationCode::POLAND->value,
        ]);
        ProcessThread::factory()->create([
            'order_id' => $order->id,
            'type' => ProcessThreadType::CUSTOM_REBUILD,
            'state' => CustomRebuildState::REPAIRED_PL->value,
            'responsible_party' => ResponsibleParty::POLAND,
            'responsible_organization_id' => $this->poland->id,
        ]);
        $this->actingAs($this->akOperator)->post(route('shipments.items.store', $shipment), [
            'qr_token' => $foreignItem->qr_token, 'idempotency_key' => 'wrong-direction', 'expected_lock_version' => 0,
        ])->assertSessionHasErrors('shipment');
        $this->actingAs($this->plOperator)->post(route('shipments.items.store', $shipment), [
            'qr_token' => $foreignItem->qr_token, 'idempotency_key' => 'wrong-sender', 'expected_lock_version' => 0,
        ])->assertForbidden();

        self::assertSame(0, $shipment->shipmentItems()->count());
        self::assertNull($foreignItem->fresh()->active_shipment_id);
    }

    public function test_controls_match_server_authorization_and_read_only_user_cannot_mutate(): void
    {
        [$item] = $this->outboundItem();
        $shipment = $this->shipment();
        $readOnly = User::factory()->create([
            'organization_id' => $this->autokluby->id,
            'role' => UserRole::READ_ONLY,
        ]);

        $this->actingAs($readOnly)->get(route('shipments.show', $shipment))
            ->assertOk()
            ->assertDontSee('Skenování kusů')
            ->assertDontSee('Odeslat zásilku');
        $this->actingAs($readOnly)->post(route('shipments.items.store', $shipment), [
            'qr_token' => $item->qr_token, 'idempotency_key' => 'readonly-add', 'expected_lock_version' => 0,
        ])->assertForbidden();

        $this->actingAs($this->akOperator)->post(route('shipments.items.store', $shipment), [
            'qr_token' => $item->qr_token, 'idempotency_key' => 'authorized-add', 'expected_lock_version' => 0,
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->plOperator)->post(route('shipments.actions', [$shipment, ShipmentAction::SEND_SHIPMENT->value]), [
            'tracking' => 'GLS-NOT-ALLOWED', 'idempotency_key' => 'wrong-sender-send', 'expected_lock_version' => 1,
        ])->assertForbidden();
    }

    public function test_shipment_forms_require_csrf_and_czech_polish_ui_and_camera_fallback_are_present(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');
        try {
            $this->actingAs($this->akOperator)->post(route('shipments.store'), [
                'direction' => ShipmentDirection::AK_TO_PL->value,
                'carrier' => ShipmentCarrier::GLS->value,
                'idempotency_key' => 'without-csrf',
            ])->assertStatus(419);
        } finally {
            $this->app->detectEnvironment(static fn (): string => 'testing');
        }

        $shipment = $this->shipment();
        $this->actingAs($this->akOperator)->get(route('shipments.show', $shipment))
            ->assertSee('Zásilka')
            ->assertSee('AUTOKLUBY → Polsko')
            ->assertSee('id="qr_token"', false)
            ->assertSee('name="qr_token"', false)
            ->assertSee('id="start-camera"', false)
            ->assertSee('disabled aria-disabled="true" data-camera-initialized="false"', false)
            ->assertSee('/vendor/jsqr/1.4.0/jsQR.js', false)
            ->assertSee('/js/shipment-camera.js', false)
            ->assertSee('data-camera-starting="Spouštím kameru…"', false)
            ->assertSee('data-camera-denied="Přístup ke kameře byl zamítnut.', false)
            ->assertSee('data-camera-not-found=', false)
            ->assertSee('data-camera-in-use=', false)
            ->assertSee('data-camera-not-supported=', false)
            ->assertSee('data-camera-policy-blocked=', false)
            ->assertSee('data-camera-start-failed=', false)
            ->assertSee('data-qr-format-not-supported=', false)
            ->assertSee('data-qr-not-found=', false)
            ->assertDontSee('cdn.jsdelivr.net', false)
            ->assertDontSee('unpkg.com', false)
            ->assertDontSee('cdnjs.cloudflare.com', false);

        $polishShipment = Shipment::query()->create([
            'direction' => ShipmentDirection::PL_TO_AK,
            'carrier' => ShipmentCarrier::DPD,
            'status' => ShipmentStatus::PREPARED,
            'sender_organization_id' => $this->poland->id,
            'recipient_organization_id' => $this->autokluby->id,
            'created_by' => $this->plOperator->id,
        ]);
        $this->actingAs($this->plOperator)->get(route('shipments.show', $polishShipment))
            ->assertOk()
            ->assertSee('data-camera-starting="Uruchamiam kamerę…"', false)
            ->assertSee('data-camera-denied="Dostęp do kamery został odrzucony.', false);

        $this->actingAs($this->plOperator)->get(route('shipments.create'))
            ->assertOk()
            ->assertSee('Nowa przesyłka')
            ->assertSee('Polska → AUTOKLUBY')
            ->assertSee('DPD');
    }

    public function test_camera_uses_local_pinned_fallback_and_complete_lifecycle(): void
    {
        $cameraAsset = public_path('js/shipment-camera.js');
        $fallbackAsset = public_path('vendor/jsqr/1.4.0/jsQR.js');
        $packageFile = public_path('vendor/jsqr/1.4.0/package.json');
        $licenseFile = public_path('vendor/jsqr/1.4.0/LICENSE');

        self::assertFileExists($cameraAsset);
        self::assertFileExists($fallbackAsset);
        self::assertFileExists($packageFile);
        self::assertFileExists($licenseFile);
        self::assertSame('bc40c8a15196236b2314db0856f72ca0b49980cd5413b8c852a7349f5fee0859', hash_file('sha256', $fallbackAsset));
        $package = json_decode(file_get_contents($packageFile), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('1.4.0', $package['version']);
        self::assertSame('Apache-2.0', $package['license']);

        $script = file_get_contents($cameraAsset);
        self::assertIsString($script);
        self::assertStringContainsString("facingMode: {ideal: 'environment'}", $script);
        self::assertStringContainsString('video: true', $script);
        self::assertStringContainsString("formats.indexOf('qr_code')", $script);
        self::assertStringContainsString("decoder = 'jsqr'", $script);
        self::assertStringContainsString('track.stop()', $script);
        self::assertStringContainsString("window.addEventListener('pagehide', stopTracks)", $script);
        self::assertStringContainsString('form.requestSubmit()', $script);
        self::assertStringNotContainsString('https://', $script);
        self::assertStringNotContainsString('http://', $script);
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
