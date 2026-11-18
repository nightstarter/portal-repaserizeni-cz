<?php

namespace Tests\Feature;

use App\Application\Messages\DiscussionNotificationDispatcher;
use App\Application\Messages\DiscussionNotificationOutbox;
use App\Application\Messages\DiscussionService;
use App\Application\Messages\PostMessageCommand;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\UserRole;
use App\Mail\DiscussionMessageNotificationMail;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderCustomer;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\IsolatedStorageSandbox;
use Tests\TestCase;

class Phase5cDiscussionNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ak;

    private Organization $pl;

    private User $akUser;

    private User $plUser;

    private DiscussionService $discussion;

    private ?IsolatedStorageSandbox $storageSandbox = null;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('discussion_notifications.enabled', true);
        config()->set('discussion_notifications.max_attempts', 5);
        config()->set('discussion_notifications.retry_minutes', 5);
        config()->set('discussion_notifications.lock_minutes', 10);
        $this->storageSandbox = IsolatedStorageSandbox::create();
        Storage::set('local', Storage::build(array_replace(
            config('filesystems.disks.local'),
            ['root' => $this->storageSandbox->diskRoot('phase5c-private')],
        )));
        $this->ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $this->pl = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $this->akUser = User::factory()->create([
            'organization_id' => $this->ak->id,
            'role' => UserRole::AK_OPERATOR,
            'email' => 'ak.operator@example.test',
            'preferred_locale' => 'cs',
        ]);
        $this->plUser = User::factory()->create([
            'organization_id' => $this->pl->id,
            'role' => UserRole::PL_OPERATOR,
            'email' => 'pl.operator@example.test',
            'preferred_locale' => 'pl',
        ]);
        $this->discussion = app(DiscussionService::class);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        try {
            $this->storageSandbox?->cleanup();
        } finally {
            parent::tearDown();
        }
    }

    public function test_message_creates_idempotent_outbox_only_for_active_authorized_second_organization_users(): void
    {
        [$visible] = $this->visibleOrder('5C-RECIPIENTS');
        [$hidden] = $this->hiddenOrder('5C-HIDDEN');
        $secondPl = User::factory()->create(['organization_id' => $this->pl->id, 'role' => UserRole::READ_ONLY]);
        $inactivePl = User::factory()->create(['organization_id' => $this->pl->id, 'role' => UserRole::PL_MANAGER, 'active' => false]);
        $otherAk = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::AK_OPERATOR]);

        $message = $this->discussion->post($visible, new PostMessageCommand($this->akUser, 'Bezpečná zpráva', '5c-recipients'));
        self::assertEqualsCanonicalizing([$this->plUser->id, $secondPl->id], OutboxEvent::query()->pluck('recipient_user_id')->all());
        self::assertFalse(OutboxEvent::query()->where('recipient_user_id', $inactivePl->id)->exists());
        self::assertFalse(OutboxEvent::query()->where('recipient_user_id', $otherAk->id)->exists());
        self::assertFalse(OutboxEvent::query()->where('recipient_user_id', $this->akUser->id)->exists());
        app(DiscussionNotificationOutbox::class)->enqueue($message);
        self::assertSame(2, OutboxEvent::query()->count());
        self::assertSame(2, OutboxEvent::query()->distinct()->count('deduplication_key'));

        $this->discussion->post($hidden, new PostMessageCommand($this->akUser, 'Polsku skrytá', '5c-hidden'));
        self::assertSame(3, OutboxEvent::query()->count());
        self::assertTrue(OutboxEvent::query()->whereNull('recipient_user_id')->where('deduplication_key', 'like', '%:no-recipient')->exists());
        Mail::assertNothingSent();
        $result = app(DiscussionNotificationDispatcher::class)->dispatch(3);
        self::assertSame(2, $result['sent']);
        self::assertSame(1, $result['failed']);
        self::assertSame(OutboxEvent::FAILED, OutboxEvent::query()->whereNull('recipient_user_id')->sole()->status);
    }

    public function test_message_keeps_a_traceable_outbox_request_when_target_organization_is_inactive(): void
    {
        [$order] = $this->visibleOrder('5C-INACTIVE-TARGET');
        $this->pl->update(['active' => false]);

        $message = $this->discussion->post($order, new PostMessageCommand($this->akUser, 'Bez aktivní druhé strany', '5c-inactive-target'));
        $event = OutboxEvent::query()->sole();
        self::assertSame($message->id, $event->aggregate_id);
        self::assertNull($event->recipient_user_id);
        self::assertSame('discussion-email:'.$message->id.':no-recipient', $event->deduplication_key);
        self::assertSame(OutboxEvent::PENDING, $event->status);
        Mail::assertNothingSent();
    }

    public function test_polish_message_targets_czech_users_and_email_variants_contain_no_pii_body_or_attachment_data(): void
    {
        [$order] = $this->visibleOrder('5C-SAFE-MAIL');
        OrderCustomer::factory()->for($order)->create([
            'name' => 'Citlivý zákazník',
            'email' => 'customer-secret@example.test',
            'phone' => '+420777123456',
        ]);
        $message = $this->discussion->post($order, new PostMessageCommand(
            $this->plUser,
            'TAJNY TEXT ZPRAVY',
            '5c-safe-mail',
            [$this->validPdf('tajna-priloha.pdf')],
        ));
        self::assertSame([$this->akUser->id], OutboxEvent::query()->pluck('recipient_user_id')->all());
        app(DiscussionNotificationDispatcher::class)->dispatch(50);

        Mail::assertSent(DiscussionMessageNotificationMail::class, function (DiscussionMessageNotificationMail $mail) use ($message, $order): bool {
            $rendered = $mail->render();
            self::assertTrue($mail->hasTo($this->akUser->email));
            self::assertSame('Zakázka 5C-SAFE-MAIL – nová zpráva', $mail->envelope()->subject);
            self::assertStringContainsString(route('orders.discussion.index', $order), $rendered);
            foreach (['Citlivý zákazník', 'customer-secret@example.test', '+420777123456', 'TAJNY TEXT ZPRAVY', 'tajna-priloha.pdf', $message->attachments->sole()->storage_path, $message->public_token] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $rendered);
            }

            return true;
        });
        $event = OutboxEvent::query()->sole();
        self::assertSame(OutboxEvent::SENT, $event->status);
        self::assertNotNull($event->sent_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'DISCUSSION_NOTIFICATION_SENT', 'order_id' => $order->id]);

        Mail::fake();
        $this->discussion->post($order, new PostMessageCommand($this->akUser, 'Polská varianta', '5c-polish-mail'));
        app(DiscussionNotificationDispatcher::class)->dispatch(50);
        Mail::assertSent(DiscussionMessageNotificationMail::class, fn (DiscussionMessageNotificationMail $mail): bool => $mail->hasTo($this->plUser->email)
            && $mail->envelope()->subject === 'Zlecenie 5C-SAFE-MAIL – nowa wiadomość');
    }

    public function test_temporary_failure_retries_exponentially_without_losing_message_or_changing_workflow(): void
    {
        [$order, $thread] = $this->visibleOrder('5C-RETRY');
        $originalState = $thread->state;
        $message = $this->discussion->post($order, new PostMessageCommand($this->akUser, 'Zůstane uložená', '5c-retry'));
        Mail::shouldReceive('to')->twice()->andThrow(new \RuntimeException('smtp://user:SECRET_PASSWORD@host customer-secret@example.test'));

        $result = app(DiscussionNotificationDispatcher::class)->dispatch(1);
        self::assertSame(1, $result['retried']);
        $event = OutboxEvent::query()->sole();
        self::assertSame(OutboxEvent::PENDING, $event->status);
        self::assertSame(1, $event->attempts);
        self::assertLessThanOrEqual(1, abs($event->next_attempt_at->diffInSeconds(now()->addMinutes(5))));
        self::assertStringNotContainsString('SECRET_PASSWORD', $event->last_error);
        self::assertStringNotContainsString('customer-secret', $event->last_error);
        self::assertTrue(Message::query()->whereKey($message)->exists());
        self::assertSame($originalState, $thread->fresh()->state);

        $event->update(['next_attempt_at' => now(), 'available_at' => now()]);
        self::assertSame(1, app(DiscussionNotificationDispatcher::class)->dispatch(1)['retried']);
        self::assertSame(OutboxEvent::PENDING, $event->fresh()->status);
        self::assertSame(2, $event->fresh()->attempts);
        self::assertLessThanOrEqual(1, abs($event->fresh()->next_attempt_at->diffInSeconds(now()->addMinutes(10))));
    }

    public function test_max_attempts_fail_permanently_with_sanitized_error_and_audit(): void
    {
        config()->set('discussion_notifications.max_attempts', 2);
        [$order] = $this->visibleOrder('5C-FAILED');
        $this->discussion->post($order, new PostMessageCommand($this->akUser, 'Neodeslaná', '5c-failed'));
        OutboxEvent::query()->update(['attempts' => 1]);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('password=hunter2'));

        app(DiscussionNotificationDispatcher::class)->dispatch(1);
        $event = OutboxEvent::query()->sole();
        self::assertSame(OutboxEvent::FAILED, $event->status);
        self::assertSame(2, $event->attempts);
        self::assertNotNull($event->failed_at);
        self::assertNull($event->next_attempt_at);
        self::assertStringNotContainsString('hunter2', $event->last_error);
        $this->assertDatabaseHas('audit_log', ['action' => 'DISCUSSION_NOTIFICATION_FAILED', 'order_id' => $order->id]);
    }

    public function test_fresh_worker_lock_is_not_stolen_and_abandoned_lock_is_recovered(): void
    {
        [$order] = $this->visibleOrder('5C-LOCK');
        $this->discussion->post($order, new PostMessageCommand($this->akUser, 'Lock', '5c-lock'));
        $event = OutboxEvent::query()->sole();
        $event->update([
            'status' => OutboxEvent::PROCESSING,
            'locked_at' => now(),
            'lock_token' => '11111111-1111-4111-8111-111111111111',
        ]);

        self::assertSame(0, app(DiscussionNotificationDispatcher::class)->dispatch(1)['claimed']);
        Mail::assertNothingSent();
        $event->update(['locked_at' => now()->subMinutes(11)]);
        self::assertSame(1, app(DiscussionNotificationDispatcher::class)->dispatch(1)['sent']);
        Mail::assertSentCount(1);
        self::assertSame(OutboxEvent::SENT, $event->fresh()->status);
        self::assertSame(1, $event->fresh()->attempts);
    }

    public function test_disabled_flag_keeps_outbox_untouched_and_command_never_contacts_mailer(): void
    {
        config()->set('discussion_notifications.enabled', false);
        [$order] = $this->visibleOrder('5C-DISABLED');
        $this->discussion->post($order, new PostMessageCommand($this->akUser, 'Bez SMTP', '5c-disabled'));
        $before = OutboxEvent::query()->sole()->toArray();

        $this->artisan('notifications:dispatch', ['--limit' => 50])
            ->expectsOutput('Discussion e-mail delivery is disabled; outbox rows were left unchanged.')
            ->assertSuccessful();
        Mail::assertNothingSent();
        self::assertSame($before, OutboxEvent::query()->sole()->toArray());
    }

    public function test_admin_page_is_ak_admin_only_masks_recipient_and_manual_retry_is_csrf_audited_and_idempotent(): void
    {
        [$order] = $this->visibleOrder('5C-ADMIN');
        $this->discussion->post($order, new PostMessageCommand($this->akUser, 'Admin', '5c-admin'));
        $event = OutboxEvent::query()->sole();
        $event->update([
            'status' => OutboxEvent::FAILED,
            'attempts' => 5,
            'failed_at' => now(),
            'last_error' => 'E-mail delivery failed after the maximum number of attempts.',
        ]);
        $admin = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::AK_ADMIN]);
        config()->set('discussion_notifications.enabled', false);

        $this->actingAs($this->akUser)->get(route('admin.notifications.index'))->assertForbidden();
        $this->actingAs($this->plUser)->get(route('admin.notifications.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.notifications.index'))->assertOk()
            ->assertSee('Doručování e-mailů je vypnuté.')
            ->assertSee('p***@e***')
            ->assertDontSee($this->plUser->email)
            ->assertSee('5C-ADMIN')
            ->assertSee('Zkusit znovu')
            ->assertSee('name="_token"', false);

        $this->app->detectEnvironment(static fn (): string => 'local');
        try {
            $this->post(route('admin.notifications.retry', $event))->assertStatus(419);
        } finally {
            $this->app->detectEnvironment(static fn (): string => 'testing');
        }

        $this->actingAs($admin)->post(route('admin.notifications.retry', $event))->assertRedirect();
        $event->refresh();
        self::assertSame(OutboxEvent::PENDING, $event->status);
        self::assertSame(0, $event->attempts);
        self::assertNull($event->failed_at);
        self::assertSame(1, DB::table('audit_log')->where('action', 'DISCUSSION_NOTIFICATION_MANUAL_RETRY')->count());
        $this->actingAs($admin)->post(route('admin.notifications.retry', $event))->assertRedirect();
        self::assertSame(1, DB::table('audit_log')->where('action', 'DISCUSSION_NOTIFICATION_MANUAL_RETRY')->count());
    }

    private function visibleOrder(string $number): array
    {
        $order = Order::factory()->create(['order_no' => $number, 'source_order_id' => $number]);
        $thread = ProcessThread::factory()->create([
            'order_id' => $order->id,
            'type' => ProcessThreadType::CUSTOM_REBUILD,
            'state' => 'SENT_TO_PL',
            'responsible_party' => ResponsibleParty::POLAND,
            'responsible_organization_id' => $this->pl->id,
        ]);

        return [$order, $thread];
    }

    private function hiddenOrder(string $number): array
    {
        $order = Order::factory()->create(['order_no' => $number, 'source_order_id' => $number]);
        $thread = ProcessThread::factory()->create([
            'order_id' => $order->id,
            'type' => ProcessThreadType::CUSTOM_REBUILD,
            'state' => 'RECEIVED_AK',
            'responsible_party' => ResponsibleParty::AUTOKLUBY,
            'responsible_organization_id' => $this->ak->id,
        ]);

        return [$order, $thread];
    }

    private function validPdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }
}
