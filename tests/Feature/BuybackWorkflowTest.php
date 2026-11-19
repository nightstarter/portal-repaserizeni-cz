<?php

namespace Tests\Feature;

use App\Application\Documents\DocumentUploadCommand;
use App\Application\Documents\PrivateDocumentService;
use App\Application\Messages\DiscussionNotificationDispatcher;
use App\Application\Orders\BuybackCommand;
use App\Application\Orders\BuybackOrderService;
use App\Application\Orders\BuybackWorkflow;
use App\Application\Orders\CreateBuybackCommand;
use App\Application\Shipments\ShipmentCommand;
use App\Application\Shipments\ShipmentService;
use App\Enums\BuybackAction;
use App\Enums\BuybackPriceType;
use App\Enums\BuybackState;
use App\Enums\DocumentType;
use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ShipmentCarrier;
use App\Enums\ShipmentDirection;
use App\Enums\UserRole;
use App\Exceptions\WorkflowConflictException;
use App\Mail\BuybackCustomerOfferMail;
use App\Models\BuybackPriceQuote;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\ProcessThread;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\IsolatedStorageSandbox;
use Tests\TestCase;

class BuybackWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ak;

    private Organization $pl;

    private User $operator;

    private User $admin;

    private User $poland;

    private User $readOnly;

    private BuybackOrderService $orders;

    private BuybackWorkflow $workflow;

    private ?IsolatedStorageSandbox $storageSandbox = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['buyback.customer_email_enabled' => false]);
        $this->ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY]);
        $this->pl = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'Polsko']);
        $this->operator = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::AK_OPERATOR]);
        $this->admin = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::AK_ADMIN]);
        $this->poland = User::factory()->create(['organization_id' => $this->pl->id, 'role' => UserRole::PL_OPERATOR]);
        $this->readOnly = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::READ_ONLY]);
        $this->orders = app(BuybackOrderService::class);
        $this->workflow = app(BuybackWorkflow::class);
    }

    protected function tearDown(): void
    {
        try {
            $this->storageSandbox?->cleanup();
        } finally {
            parent::tearDown();
        }
    }

    public function test_system_order_number_creation_is_unique_idempotent_and_not_legacy(): void
    {
        $first = $this->createOrder('create-a');
        $replay = $this->createOrder('create-a');
        $second = $this->createOrder('create-b');

        self::assertSame($first->id, $replay->id);
        self::assertNotSame($first->order_no, $second->order_no);
        self::assertMatchesRegularExpression('/^VYK-\d{4}-\d{5}$/', $first->order_no);
        self::assertSame(OrderSource::SYSTEM_BUYOUT, $first->source);
        self::assertSame(OrderType::BUYOUT, $first->order_type);
        self::assertNull($first->source_order_id);
        self::assertSame(2, Order::query()->count());
        self::assertSame(2, ProcessThread::query()->where('type', ProcessThreadType::BUYOUT)->count());
        self::assertSame(2, (int) DB::table('buyback_order_sequences')->value('last_number'));
        try {
            $different = $this->createData();
            $different['product_code'] = 'DIFFERENT';
            $this->orders->create(new CreateBuybackCommand($this->operator, 'create-a', $different));
            self::fail('An idempotency key reused for different input must be rejected.');
        } catch (WorkflowConflictException) {
            self::assertSame(2, Order::query()->count());
        }
        $this->expectException(AuthorizationException::class);
        $this->orders->create(new CreateBuybackCommand($this->poland, 'pl-create', $this->createData()));
    }

    public function test_command_endpoint_creates_only_for_autokluby_and_replays_safely(): void
    {
        $payload = $this->createData() + [
            'idempotency_key' => 'http-create',
            'internal_offer_amount' => '1000,00',
            'internal_offer_currency' => 'CZK',
        ];
        $first = $this->actingAs($this->operator)->postJson(route('buybacks.store'), $payload)->assertCreated();
        $second = $this->actingAs($this->operator)->postJson(route('buybacks.store'), $payload)->assertCreated();
        self::assertSame($first->json('order_no'), $second->json('order_no'));
        self::assertSame(1, Order::query()->count());
        $this->actingAs($this->poland)->postJson(route('buybacks.store'), $payload + ['idempotency_key' => 'pl-http'])->assertForbidden();
    }

    public function test_prices_are_minor_units_versioned_and_seventy_percent_is_never_final(): void
    {
        $thread = $this->thread($this->createOrder('prices'));
        $this->act($thread, BuybackAction::SET_INTERNAL_PRICE, $this->operator, 'internal', ['amount_minor' => 120001, 'currency' => 'CZK']);
        $this->act($thread, BuybackAction::REQUEST_PL_OFFER, $this->operator, 'request');
        $this->act($thread, BuybackAction::SUBMIT_PL_OFFER, $this->poland, 'offer-1', ['amount_minor' => 10001, 'currency' => 'EUR']);
        $this->act($thread, BuybackAction::SUBMIT_PL_OFFER, $this->poland, 'offer-2', ['amount_minor' => 11003, 'currency' => 'EUR']);

        self::assertSame(7702, BuybackWorkflow::recommendedMinor(11003));
        self::assertSame([10001, 11003], $thread->buybackPriceQuotes()->where('type', BuybackPriceType::POLAND_OFFER)->pluck('amount_minor')->all());
        self::assertFalse($thread->buybackPriceQuotes()->where('type', BuybackPriceType::FINAL_CUSTOMER)->exists());
        self::assertSame(BuybackState::PL_OFFERED->value, $thread->fresh()->state);
        $quote = BuybackPriceQuote::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $quote->update(['amount_minor' => 1]);
    }

    public function test_only_admin_permission_can_approve_and_queue_customer_offer(): void
    {
        Mail::fake();
        $thread = $this->toWaitingFinal('approval');
        foreach ([$this->operator, $this->poland] as $actor) {
            try {
                $this->act($thread, BuybackAction::APPROVE_FINAL_PRICE, $actor, 'forbidden-'.$actor->id, ['amount_minor' => 7000, 'currency' => 'CZK']);
                self::fail('Final price approval must be permission protected.');
            } catch (AuthorizationException) {
                self::assertFalse($thread->buybackPriceQuotes()->where('type', BuybackPriceType::FINAL_CUSTOMER)->exists());
            }
        }
        $this->act($thread, BuybackAction::APPROVE_FINAL_PRICE, $this->admin, 'final', ['amount_minor' => 7000, 'currency' => 'CZK']);
        $this->act($thread, BuybackAction::CONFIRM_CUSTOMER_OFFER, $this->admin, 'confirm');
        self::assertSame(0, OutboxEvent::query()->count());
        $this->act($thread, BuybackAction::QUEUE_CUSTOMER_OFFER, $this->admin, 'queue');
        $this->act($thread, BuybackAction::QUEUE_CUSTOMER_OFFER, $this->admin, 'queue');

        self::assertSame(1, OutboxEvent::query()->where('event_type', 'BUYBACK_CUSTOMER_OFFER_EMAIL')->count());
        self::assertSame(OutboxEvent::PENDING, OutboxEvent::query()->firstOrFail()->status);
        self::assertSame(BuybackState::CUSTOMER_OFFER_READY->value, $thread->fresh()->state);
        self::assertEquals(['thread_event_id'], array_keys(OutboxEvent::query()->firstOrFail()->payload));
        $queueUrl = route('orders.buyback.actions', [$thread->order, $thread, BuybackAction::QUEUE_CUSTOMER_OFFER->value]);
        self::assertStringNotContainsString('action="'.$queueUrl.'"', $this->actingAs($this->admin)
            ->get(route('orders.show', $thread->order))->assertOk()->getContent());
        try {
            $this->act($thread, BuybackAction::RECORD_CUSTOMER_ACCEPTANCE, $this->operator, 'accept-pending');
            self::fail('A pending customer offer must not be accepted as delivered.');
        } catch (WorkflowConflictException) {
            self::assertSame(BuybackState::CUSTOMER_OFFER_READY->value, $thread->fresh()->state);
        }
        Mail::assertNothingSent();
        self::assertFalse(config('buyback.customer_email_enabled'));
    }

    public function test_customer_acceptance_requires_sent_offer_and_decision_cannot_silently_change(): void
    {
        $thread = $this->toWaitingFinal('acceptance');
        try {
            $this->act($thread, BuybackAction::RECORD_CUSTOMER_ACCEPTANCE, $this->operator, 'too-soon');
            self::fail('Acceptance before a sent offer must fail.');
        } catch (WorkflowConflictException) {
            self::assertSame(BuybackState::WAITING_FOR_FINAL_PRICE->value, $thread->fresh()->state);
        }
        $this->finishOfferAndCheck($thread);
        $this->act($thread, BuybackAction::RETAIN_AK, $this->operator, 'retain');
        self::assertSame(BuybackState::RETAINED_AK->value, $thread->fresh()->state);
        self::assertNotNull($thread->fresh()->completed_at);
        self::assertSame(0, Shipment::query()->count());
        $this->expectException(WorkflowConflictException::class);
        $this->act($thread, BuybackAction::DECIDE_PL_BUY, $this->admin, 'switch');
    }

    public function test_shipment_service_is_the_only_atomic_transport_path_to_poland(): void
    {
        $thread = $this->toChecked('shipment');
        $this->act($thread, BuybackAction::DECIDE_PL_BUY, $this->operator, 'pl-buys');
        $item = $thread->item;
        $item->update(['current_location' => OrganizationCode::AUTOKLUBY->value]);
        $shipments = app(ShipmentService::class);
        $shipment = $shipments->create(new ShipmentCommand($this->operator, 'ship-create', [
            'direction' => ShipmentDirection::AK_TO_PL->value,
            'carrier' => ShipmentCarrier::GLS->value,
            'tracking' => 'BUYBACK-TRACK',
        ]));
        $shipments->addItem($shipment, $item->fresh(), $thread->fresh(), new ShipmentCommand($this->operator, 'ship-add', [], $shipment->fresh()->lock_version));
        $shipments->send($shipment->fresh(), new ShipmentCommand($this->operator, 'ship-send', ['tracking' => 'BUYBACK-TRACK'], $shipment->fresh()->lock_version));
        self::assertSame(BuybackState::SENT_TO_PL->value, $thread->fresh()->state);
        self::assertSame('SENT', $shipment->fresh()->status->value);
        $shipments->confirmDelivery($shipment->fresh(), new ShipmentCommand($this->poland, 'ship-receive', [], $shipment->fresh()->lock_version));
        self::assertSame(BuybackState::INVOICE_TO_PL_REQUIRED->value, $thread->fresh()->state);
        self::assertSame(OrganizationCode::POLAND->value, $item->fresh()->current_location);

        $response = $this->actingAs($this->operator)->postJson(route('orders.buyback.actions', [
            $thread->order, $thread, BuybackAction::SEND_TO_PL->value,
        ]), ['idempotency_key' => 'bypass', 'expected_lock_version' => $thread->fresh()->lock_version]);
        $response->assertUnprocessable();
    }

    public function test_private_invoice_download_payment_ledger_and_replacement_history(): void
    {
        $this->storageSandbox = IsolatedStorageSandbox::create();
        config([
            'documents.disk' => 'documents-test',
            'filesystems.disks.documents-test' => [
                'driver' => 'local',
                'root' => $this->storageSandbox->diskRoot('documents-test'),
                'visibility' => 'private',
                'throw' => false,
            ],
        ]);
        $thread = $this->toInvoiceRequired('invoice');
        $service = app(PrivateDocumentService::class);
        $document = $service->uploadBuybackInvoice($thread->order, $thread, $this->documentCommand($thread, 'invoice-one'));
        self::assertSame(DocumentType::BUYBACK_INVOICE_TO_POLAND, $document->type);
        self::assertSame(BuybackState::WAITING_FOR_PL_PAYMENT->value, $thread->fresh()->state);
        Storage::disk('documents-test')->assertExists($document->storage_name);
        $download = $this->actingAs($this->poland)->get(route('documents.download', $document))->assertOk();
        self::assertStringContainsString('buyback-invoice-', (string) $download->headers->get('content-disposition'));
        self::assertStringNotContainsString('invoice-one', (string) $download->headers->get('content-disposition'));

        $replacement = $service->uploadBuybackInvoice($thread->order, $thread->fresh(), $this->documentCommand($thread->fresh(), 'invoice-two', ['reason' => 'oprava']), $document);
        self::assertSame('INVALIDATED', $document->fresh()->status->value);
        self::assertSame($document->id, $replacement->replaces_document_id);
        try {
            $this->act($thread, BuybackAction::MARK_PAID_BY_PL, $this->operator, 'operator-paid', ['document_id' => $replacement->id]);
            self::fail('Accounting payment confirmation requires its explicit permission.');
        } catch (AuthorizationException) {
            self::assertSame(BuybackState::WAITING_FOR_PL_PAYMENT->value, $thread->fresh()->state);
        }
        $this->act($thread, BuybackAction::MARK_PAID_BY_PL, $this->admin, 'paid', ['document_id' => $replacement->id]);
        self::assertSame(BuybackState::PAID_BY_PL->value, $thread->fresh()->state);
        $this->assertDatabaseHas('finance_entries', ['process_thread_id' => $thread->id, 'type' => 'BUYBACK_POLAND_PAYMENT', 'status' => 'SETTLED']);
        $this->assertDatabaseHas('audit_log', ['action' => 'BUYBACK_ACTION', 'subject_id' => $thread->id]);
    }

    public function test_poland_row_scope_safe_404_and_html_exclude_customer_pii_and_prices(): void
    {
        $visible = $this->createOrder('visible');
        $hidden = $this->createOrder('hidden');
        $visibleThread = $this->thread($visible);
        $this->act($visibleThread, BuybackAction::SET_INTERNAL_PRICE, $this->operator, 'secret-price', ['amount_minor' => 999999, 'currency' => 'CZK']);
        $this->act($visibleThread, BuybackAction::REQUEST_PL_OFFER, $this->operator, 'make-visible');
        $this->act($visibleThread, BuybackAction::SUBMIT_PL_OFFER, $this->poland, 'visible-offer', ['amount_minor' => 10000, 'currency' => 'EUR']);
        $this->act($visibleThread, BuybackAction::REQUEST_FINAL_PRICE, $this->operator, 'hide-customer-phase');

        $html = $this->actingAs($this->poland)->get(route('orders.show', $visible))->assertOk()->getContent();
        self::assertStringNotContainsString('Customer Secret', $html);
        self::assertStringNotContainsString('secret@example.test', $html);
        self::assertStringNotContainsString('999999', $html);
        self::assertStringNotContainsString(BuybackState::WAITING_FOR_FINAL_PRICE->value, $html);
        $this->actingAs($this->poland)->postJson(route('orders.buyback.actions', [
            $hidden, $this->thread($hidden), BuybackAction::SUBMIT_PL_OFFER->value,
        ]), ['idempotency_key' => 'foreign', 'expected_lock_version' => 0, 'amount_minor' => 10, 'currency' => 'EUR'])->assertNotFound();
        $this->actingAs($this->readOnly)->postJson(route('orders.buyback.actions', [
            $visible, $visibleThread, BuybackAction::REQUEST_FINAL_PRICE->value,
        ]), ['idempotency_key' => 'readonly', 'expected_lock_version' => 2])->assertForbidden();
    }

    public function test_stale_version_replay_and_unrelated_domains_remain_unchanged(): void
    {
        $thread = $this->thread($this->createOrder('locking'));
        $event = $this->act($thread, BuybackAction::SET_INTERNAL_PRICE, $this->operator, 'same', ['amount_minor' => 1, 'currency' => 'CZK']);
        $replay = $this->workflow->execute($thread, new BuybackCommand(
            BuybackAction::SET_INTERNAL_PRICE, $this->operator, 'same', ['amount_minor' => 1, 'currency' => 'CZK'], 0,
        ));
        self::assertSame($event->id, $replay->id);
        try {
            $this->workflow->execute($thread, new BuybackCommand(BuybackAction::REQUEST_PL_OFFER, $this->operator, 'stale', [], 0));
            self::fail('Stale command must fail.');
        } catch (WorkflowConflictException) {
            self::assertSame(BuybackState::DRAFT->value, $thread->fresh()->state);
        }
        self::assertSame(0, ProcessThread::query()->whereIn('type', [
            ProcessThreadType::WARRANTY, ProcessThreadType::CUSTOM_REBUILD,
            ProcessThreadType::CORE_RETURN, ProcessThreadType::STOCK_REQUEST, ProcessThreadType::STOCK_REPLENISHMENT,
        ])->count());
        self::assertSame(0, $thread->order->messages()->count());
    }

    public function test_buyback_ui_menu_empty_create_exact_money_roles_locales_and_xss_protection(): void
    {
        $this->actingAs($this->operator)->get(route('buybacks.index'))->assertOk()
            ->assertSee(__('app.buyback_empty'))
            ->assertSee('href="'.route('buybacks.index').'"', false)
            ->assertSee('aria-current="page"', false);
        $this->actingAs($this->operator)->get(route('buybacks.create'))->assertOk()->assertSee('data-submit-once', false);
        $this->actingAs($this->poland)->get(route('buybacks.create'))->assertForbidden();
        $this->actingAs($this->readOnly)->get(route('buybacks.create'))->assertForbidden();

        $response = $this->actingAs($this->operator)->post(route('buybacks.store'), [
            'idempotency_key' => 'ui-create', 'customer_name' => 'Citlivý zákazník',
            'customer_email' => 'customer-ui@example.test', 'customer_phone' => '+420 123 456',
            'product_code' => 'UI-001', 'product_name' => '<script>alert(1)</script>',
            'internal_note' => '<img src=x onerror=alert(2)>',
            'internal_offer_amount' => '1 234,56', 'internal_offer_currency' => 'czk',
        ]);
        $order = Order::query()->where('creation_idempotency_key', 'ui-create')->sole();
        $response->assertRedirect(route('orders.show', $order));
        self::assertMatchesRegularExpression('/^VYK-\d{4}-\d{5}$/', $order->order_no);
        self::assertSame(123456, $order->processThreads()->sole()->buybackPriceQuotes()->sole()->amount_minor);
        $html = $this->actingAs($this->operator)->get(route('orders.show', $order))->assertOk()->getContent();
        self::assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);

        $thread = $this->thread($order);
        $this->act($thread, BuybackAction::REQUEST_PL_OFFER, $this->operator, 'ui-visible-pl');
        $this->poland->update(['preferred_locale' => 'pl']);
        $plHtml = $this->actingAs($this->poland)->get(route('buybacks.index'))->assertOk()
            ->assertSee('Odkup')->getContent();
        foreach (['Citlivý zákazník', 'customer-ui@example.test', '+420 123 456', 'Wewnętrzna oferta AUTOKLUBY', 'Oferta dla klienta'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $plHtml);
        }
        $this->actingAs($this->poland)->get(route('orders.show', $order))->assertOk()
            ->assertDontSee('customer-ui@example.test')->assertDontSee('1234,56')->assertDontSee('70 %');
    }

    public function test_customer_offer_worker_uses_existing_outbox_flag_mail_and_masked_admin(): void
    {
        Mail::fake();
        $thread = $this->toWaitingFinal('worker-ui');
        $this->act($thread, BuybackAction::APPROVE_FINAL_PRICE, $this->admin, 'worker-final', ['amount_minor' => 7001, 'currency' => 'CZK']);
        $this->act($thread, BuybackAction::CONFIRM_CUSTOMER_OFFER, $this->admin, 'worker-confirm');
        $this->act($thread, BuybackAction::QUEUE_CUSTOMER_OFFER, $this->admin, 'worker-queue');
        $event = OutboxEvent::query()->where('event_type', 'BUYBACK_CUSTOMER_OFFER_EMAIL')->sole();
        $before = $event->toArray();

        config(['discussion_notifications.enabled' => false, 'buyback.customer_email_enabled' => false]);
        self::assertTrue(app(DiscussionNotificationDispatcher::class)->dispatch(50)['disabled']);
        self::assertSame($before, $event->fresh()->toArray());
        self::assertSame(BuybackState::CUSTOMER_OFFER_READY->value, $thread->fresh()->state);
        Mail::assertNothingSent();

        $event->update(['status' => OutboxEvent::FAILED, 'failed_at' => now(), 'last_error' => 'Safe failure']);
        $adminHtml = $this->actingAs($this->admin)->get(route('admin.notifications.index'))->assertOk()->getContent();
        self::assertStringContainsString('s***@e***', $adminHtml);
        self::assertStringNotContainsString('secret@example.test', $adminHtml);
        $this->actingAs($this->operator)->post(route('admin.notifications.retry', $event))->assertForbidden();
        $this->actingAs($this->admin)->post(route('admin.notifications.retry', $event))->assertRedirect();
        self::assertSame(OutboxEvent::FAILED, $event->fresh()->status);
        config(['buyback.customer_email_enabled' => true]);
        $this->actingAs($this->admin)->post(route('admin.notifications.retry', $event))->assertRedirect();
        self::assertSame(OutboxEvent::PENDING, $event->fresh()->status);
        $this->assertDatabaseHas('audit_log', ['action' => 'BUYBACK_CUSTOMER_NOTIFICATION_MANUAL_RETRY', 'subject_id' => $thread->id]);
        $result = app(DiscussionNotificationDispatcher::class)->dispatch(50);
        self::assertSame(1, $result['sent']);
        Mail::assertSent(BuybackCustomerOfferMail::class, function (BuybackCustomerOfferMail $mail) use ($thread): bool {
            $html = $mail->render();
            self::assertTrue($mail->hasTo('secret@example.test'));
            self::assertStringContainsString($thread->order->order_no, $html);
            self::assertStringContainsString('70,01 CZK', $html);
            foreach (['Customer Secret', '100,00', 'TAJ', 'token', 'http://', 'https://'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $html);
            }

            return true;
        });
        self::assertSame(OutboxEvent::SENT, $event->fresh()->status);
        self::assertSame(BuybackState::OFFER_SENT->value, $thread->fresh()->state);
        self::assertSame(1, $thread->events()->where('action', BuybackAction::DELIVER_CUSTOMER_OFFER->value)->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'BUYBACK_CUSTOMER_NOTIFICATION_SENT', 'subject_id' => $thread->id]);
    }

    public function test_buyback_worker_does_not_steal_fresh_lock_and_recovers_with_sanitized_retry(): void
    {
        $thread = $this->toWaitingFinal('worker-lock');
        $this->act($thread, BuybackAction::APPROVE_FINAL_PRICE, $this->admin, 'lock-final', ['amount_minor' => 7000, 'currency' => 'CZK']);
        $this->act($thread, BuybackAction::CONFIRM_CUSTOMER_OFFER, $this->admin, 'lock-confirm');
        $this->act($thread, BuybackAction::QUEUE_CUSTOMER_OFFER, $this->admin, 'lock-queue');
        $event = OutboxEvent::query()->where('event_type', 'BUYBACK_CUSTOMER_OFFER_EMAIL')->sole();
        $event->update([
            'status' => OutboxEvent::PROCESSING, 'locked_at' => now(),
            'lock_token' => '11111111-1111-4111-8111-111111111111',
        ]);
        config(['discussion_notifications.enabled' => false, 'buyback.customer_email_enabled' => true]);
        Mail::fake();
        self::assertSame(0, app(DiscussionNotificationDispatcher::class)->dispatch(1)['claimed']);
        Mail::assertNothingSent();

        $event->update(['locked_at' => now()->subMinutes(11)]);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp://user:SECRET@host customer@example.test'));
        self::assertSame(1, app(DiscussionNotificationDispatcher::class)->dispatch(1)['retried']);
        $event->refresh();
        self::assertSame(OutboxEvent::PENDING, $event->status);
        self::assertSame(1, $event->attempts);
        self::assertStringNotContainsString('SECRET', $event->last_error);
        self::assertStringNotContainsString('customer@example.test', $event->last_error);
        self::assertSame(BuybackState::CUSTOMER_OFFER_READY->value, $thread->fresh()->state);
        self::assertSame(0, $thread->events()->where('action', BuybackAction::DELIVER_CUSTOMER_OFFER->value)->count());

        $event->update(['next_attempt_at' => now()->subMinute(), 'available_at' => now()->subMinute()]);
        config(['discussion_notifications.max_attempts' => 2]);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP rejected the message.'));
        self::assertSame(1, app(DiscussionNotificationDispatcher::class)->dispatch(1)['failed']);
        self::assertSame(OutboxEvent::FAILED, $event->fresh()->status);
        self::assertSame(BuybackState::CUSTOMER_OFFER_READY->value, $thread->fresh()->state);
        self::assertSame(0, $thread->events()->where('action', BuybackAction::DELIVER_CUSTOMER_OFFER->value)->count());

    }

    private function createOrder(string $key): Order
    {
        return $this->orders->create(new CreateBuybackCommand($this->operator, $key, $this->createData()));
    }

    private function createData(): array
    {
        return ['customer_name' => 'Customer Secret', 'customer_email' => 'secret@example.test',
            'customer_phone' => '+420111222333', 'product_code' => 'BUY-001', 'product_name' => 'Řízení'];
    }

    private function thread(Order $order): ProcessThread
    {
        return $order->processThreads()->where('type', ProcessThreadType::BUYOUT)->firstOrFail();
    }

    private function act(ProcessThread $thread, BuybackAction $action, User $actor, string $key, array $data = []): mixed
    {
        return $this->workflow->execute($thread, new BuybackCommand($action, $actor, $key, $data, $thread->fresh()->lock_version));
    }

    private function toWaitingFinal(string $key): ProcessThread
    {
        $thread = $this->thread($this->createOrder($key));
        $this->act($thread, BuybackAction::REQUEST_PL_OFFER, $this->operator, $key.'-request');
        $this->act($thread, BuybackAction::SUBMIT_PL_OFFER, $this->poland, $key.'-pl', ['amount_minor' => 10000, 'currency' => 'CZK']);
        $this->act($thread, BuybackAction::REQUEST_FINAL_PRICE, $this->operator, $key.'-final-request');

        return $thread;
    }

    private function finishOfferAndCheck(ProcessThread $thread): void
    {
        $this->act($thread, BuybackAction::APPROVE_FINAL_PRICE, $this->admin, 'finish-final-'.$thread->id, ['amount_minor' => 7000, 'currency' => 'CZK']);
        $this->act($thread, BuybackAction::CONFIRM_CUSTOMER_OFFER, $this->admin, 'finish-confirm-'.$thread->id);
        $this->act($thread, BuybackAction::QUEUE_CUSTOMER_OFFER, $this->admin, 'finish-queue-'.$thread->id);
        Mail::fake();
        config(['buyback.customer_email_enabled' => true]);
        self::assertSame(1, app(DiscussionNotificationDispatcher::class)->dispatch(1)['sent']);
        config(['buyback.customer_email_enabled' => false]);
        $this->act($thread, BuybackAction::RECORD_CUSTOMER_ACCEPTANCE, $this->operator, 'finish-accept-'.$thread->id);
        $this->act($thread, BuybackAction::RECEIVE_ITEM_AK, $this->operator, 'finish-receive-'.$thread->id);
        $this->act($thread, BuybackAction::CHECK_ITEM_AK, $this->operator, 'finish-check-'.$thread->id);
    }

    private function toChecked(string $key): ProcessThread
    {
        $thread = $this->toWaitingFinal($key);
        $this->finishOfferAndCheck($thread);

        return $thread;
    }

    private function toInvoiceRequired(string $key): ProcessThread
    {
        $thread = $this->toChecked($key);
        $this->act($thread, BuybackAction::DECIDE_PL_BUY, $this->operator, $key.'-decision');
        $thread->item->update(['current_location' => OrganizationCode::AUTOKLUBY->value]);
        $service = app(ShipmentService::class);
        $shipment = $service->create(new ShipmentCommand($this->operator, $key.'-ship-create', [
            'direction' => ShipmentDirection::AK_TO_PL->value, 'carrier' => ShipmentCarrier::GLS->value,
            'tracking' => strtoupper($key).'-TRACK',
        ]));
        $service->addItem($shipment, $thread->item->fresh(), $thread->fresh(), new ShipmentCommand(
            $this->operator, $key.'-ship-add', [], $shipment->fresh()->lock_version,
        ));
        $service->send($shipment->fresh(), new ShipmentCommand(
            $this->operator, $key.'-ship-send', ['tracking' => strtoupper($key).'-TRACK'], $shipment->fresh()->lock_version,
        ));
        $service->confirmDelivery($shipment->fresh(), new ShipmentCommand(
            $this->poland, $key.'-ship-receive', [], $shipment->fresh()->lock_version,
        ));

        return $thread;
    }

    private function documentCommand(ProcessThread $thread, string $key, array $data = []): DocumentUploadCommand
    {
        return new DocumentUploadCommand($this->operator,
            UploadedFile::fake()->createWithContent($key.'.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
            $key, $thread->fresh()->lock_version, 'INV-'.$key, now()->format('Y-m-d'), '100.00', 'EUR', $data);
    }
}
