<?php

namespace Tests\Feature;

use App\Application\Messages\DiscussionService;
use App\Application\Messages\PostMessageCommand;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\UserRole;
use App\Exceptions\WorkflowConflictException;
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
use Illuminate\Validation\ValidationException;
use Tests\Support\IsolatedStorageSandbox;
use Tests\TestCase;

class Phase5aDiscussionTest extends TestCase
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
        $this->storageSandbox = IsolatedStorageSandbox::create();
        Storage::set('local', Storage::build(array_replace(
            config('filesystems.disks.local'),
            ['root' => $this->storageSandbox->diskRoot('message-private')],
        )));
        Storage::set('public', Storage::build(array_replace(
            config('filesystems.disks.public'),
            ['root' => $this->storageSandbox->diskRoot('public')],
        )));
        $this->ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $this->pl = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $this->akUser = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::AK_OPERATOR]);
        $this->plUser = User::factory()->create(['organization_id' => $this->pl->id, 'role' => UserRole::PL_OPERATOR, 'preferred_locale' => 'pl']);
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

    public function test_message_creation_is_audited_outboxed_and_empty_message_is_rejected(): void
    {
        [$order] = $this->visibleOrder('5A-CREATE');
        $response = $this->actingAs($this->akUser)->post(route('orders.discussion.store', $order), [
            'body' => 'Technický dotaz bez osobních údajů.',
            'idempotency_key' => 'create-message',
        ]);

        $response->assertRedirect(route('orders.discussion.index', $order));
        $message = Message::query()->sole();
        self::assertSame($this->akUser->id, $message->author_user_id);
        self::assertSame($this->ak->id, $message->author_organization_id);
        self::assertSame(1, $message->reads()->where('user_id', $this->akUser->id)->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'DISCUSSION_MESSAGE_POSTED', 'order_id' => $order->id]);
        $event = OutboxEvent::query()->sole();
        self::assertSame('discussion.message-posted.v1', $event->event_type);
        self::assertSame('PENDING', $event->status);
        self::assertArrayNotHasKey('body', $event->payload);
        Mail::assertNothingSent();

        $this->actingAs($this->akUser)->post(route('orders.discussion.store', $order), [
            'body' => '   ',
            'idempotency_key' => 'empty-message',
        ])->assertSessionHasErrors('body');
        self::assertSame(1, Message::query()->count());
    }

    public function test_direct_urls_enforce_order_isolation_and_polish_row_scope_without_customer_pii(): void
    {
        [$visible] = $this->visibleOrder('5A-VISIBLE');
        [$hidden] = $this->hiddenOrder('5A-HIDDEN');
        OrderCustomer::factory()->for($visible)->create([
            'name' => 'Tajny klient 5A',
            'email' => 'pii-5a@example.test',
            'phone' => '+420777555444',
        ]);
        $this->discussion->post($visible, new PostMessageCommand($this->akUser, 'Viditelná zpráva', 'visible-message'));
        $this->discussion->post($hidden, new PostMessageCommand($this->akUser, 'Skrytá zpráva', 'hidden-message'));

        $this->actingAs($this->plUser)->get(route('orders.discussion.index', $visible))->assertOk()
            ->assertSee('Viditelná zpráva')
            ->assertDontSee('Tajny klient 5A')
            ->assertDontSee('pii-5a@example.test')
            ->assertDontSee('+420777555444')
            ->assertDontSee('Skrytá zpráva');
        $this->actingAs($this->plUser)->get(route('orders.discussion.index', $hidden))->assertNotFound();
        $this->actingAs($this->plUser)->post(route('orders.discussion.store', $hidden), [
            'body' => 'Neoprávněný pokus',
            'idempotency_key' => 'forbidden-message',
        ])->assertNotFound();
        $readOnly = User::factory()->create([
            'organization_id' => $this->pl->id,
            'role' => UserRole::READ_ONLY,
            'preferred_locale' => 'pl',
        ]);
        self::assertFalse($readOnly->can('create', [Message::class, $visible]));
        $this->actingAs($readOnly)->get(route('orders.discussion.index', $visible))->assertOk()->assertDontSee('Nowa wiadomość');
        $this->actingAs($readOnly)->post(route('orders.discussion.store', $visible), [
            'body' => 'Read-only nesmí zapisovat',
            'idempotency_key' => 'read-only-message',
        ])->assertForbidden();
        self::assertSame(2, Message::query()->count());
    }

    public function test_private_attachment_metadata_download_and_authorization_hide_storage_path(): void
    {
        [$visible] = $this->visibleOrder('5A-FILE');
        [$hidden] = $this->hiddenOrder('5A-FILE-HIDDEN');
        $file = $this->validPdf('technicky-dotaz.pdf');
        $checksum = hash_file('sha256', $file->getRealPath());
        $message = $this->discussion->post($visible, new PostMessageCommand($this->akUser, 'Zpráva s přílohou', 'file-message', [$file]));
        $attachment = $message->attachments->sole();

        self::assertSame('technicky-dotaz.pdf', $attachment->original_name);
        self::assertSame('application/pdf', $attachment->mime);
        self::assertSame($checksum, $attachment->checksum_sha256);
        self::assertStringStartsWith('message-attachments/', $attachment->storage_path);
        self::assertStringNotContainsString('technicky-dotaz', $attachment->storage_path);
        Storage::disk('local')->assertExists($attachment->storage_path);
        self::assertSame([], Storage::disk('public')->allFiles());

        $page = $this->actingAs($this->plUser)->get(route('orders.discussion.index', $visible))->assertOk();
        $page->assertSee(route('message-attachments.download', $attachment), false)
            ->assertDontSee($attachment->storage_path);
        $this->actingAs($this->plUser)->get(route('message-attachments.download', $attachment))->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=technicky-dotaz.pdf')
            ->assertHeader('x-content-type-options', 'nosniff');

        $hiddenMessage = $this->discussion->post($hidden, new PostMessageCommand($this->akUser, 'Skrytá příloha', 'hidden-file', [$this->validPdf('hidden.pdf')]));
        $this->actingAs($this->plUser)->get(route('message-attachments.download', $hiddenMessage->attachments->sole()))->assertNotFound();
        auth()->logout();
        $this->get(route('message-attachments.download', $attachment))->assertRedirect(route('login'));
    }

    public function test_forged_mime_oversize_and_double_extension_are_rejected_without_storage_residue(): void
    {
        [$order] = $this->visibleOrder('5A-UNSAFE');
        config()->set('messages.max_kilobytes', 1);
        $cases = [
            UploadedFile::fake()->create('large.pdf', 2, 'application/pdf'),
            $this->realFile('spoof.pdf', '<?php echo 1;', 'application/pdf'),
            UploadedFile::fake()->createWithContent('payload.php.pdf', "%PDF-1.4\n%%EOF"),
        ];
        foreach ($cases as $index => $file) {
            try {
                $this->discussion->post($order, new PostMessageCommand($this->akUser, 'Neplatná příloha', 'unsafe-'.$index, [$file]));
                self::fail('Expected attachment validation to fail for case '.$index.'.');
            } catch (ValidationException) {
                self::assertSame(0, Message::query()->count());
                self::assertSame([], Storage::disk('local')->allFiles());
            }
        }
    }

    public function test_database_rollback_removes_only_the_new_message_attachment(): void
    {
        [$order] = $this->visibleOrder('5A-ROLLBACK');
        DB::listen(static function ($query): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'insert into "outbox_events"')) {
                throw new \RuntimeException('simulated outbox failure');
            }
        });

        try {
            $this->discussion->post($order, new PostMessageCommand($this->akUser, 'Rollback zprávy', 'rollback-message', [$this->validPdf('rollback.pdf')]));
            self::fail('Expected the message transaction to roll back.');
        } catch (\RuntimeException $exception) {
            self::assertSame('simulated outbox failure', $exception->getMessage());
            self::assertSame(0, Message::query()->count());
            self::assertSame(0, OutboxEvent::query()->count());
            self::assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_idempotency_prevents_duplicates_and_rejects_key_reuse(): void
    {
        [$order] = $this->visibleOrder('5A-IDEMPOTENT');
        $command = new PostMessageCommand($this->akUser, 'Jednou a dost', 'same-key');
        $first = $this->discussion->post($order, $command);
        $second = $this->discussion->post($order, $command);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, Message::query()->count());
        self::assertSame(1, OutboxEvent::query()->count());
        try {
            $this->discussion->post($order, new PostMessageCommand($this->akUser, 'Jiný obsah', 'same-key'));
            self::fail('Expected an idempotency conflict.');
        } catch (WorkflowConflictException) {
            self::assertSame(1, Message::query()->count());
        }
    }

    public function test_reads_and_unread_counts_are_per_user_and_exclude_own_new_message(): void
    {
        [$order] = $this->visibleOrder('5A-READS');
        $this->discussion->post($order, new PostMessageCommand($this->akUser, 'Zpráva z AUTOKLUBY', 'read-ak'));
        self::assertSame(0, $this->discussion->unreadCount($order, $this->akUser));
        self::assertSame(1, $this->discussion->unreadCount($order, $this->plUser));

        $this->discussion->post($order, new PostMessageCommand($this->plUser, 'Odpověď z Polska', 'read-pl'));
        self::assertSame(1, $this->discussion->unreadCount($order, $this->plUser));
        self::assertSame(1, $this->discussion->unreadCount($order, $this->akUser));
        $this->actingAs($this->plUser)->post(route('orders.discussion.read', $order))->assertRedirect();
        self::assertSame(0, $this->discussion->unreadCount($order, $this->plUser));
        self::assertSame(1, $this->discussion->unreadCount($order, $this->akUser));
    }

    public function test_posting_message_does_not_change_order_or_workflow(): void
    {
        [$order, $thread] = $this->visibleOrder('5A-NO-WORKFLOW');
        $orderFields = ['closed_at', 'updated_at'];
        $threadFields = ['state', 'responsible_party', 'responsible_organization_id', 'completed_at', 'lock_version', 'updated_at'];
        $orderSnapshot = collect($orderFields)->mapWithKeys(fn (string $field): array => [$field => $order->getRawOriginal($field)])->all();
        $threadSnapshot = collect($threadFields)->mapWithKeys(fn (string $field): array => [$field => $thread->getRawOriginal($field)])->all();
        $eventCount = $thread->events()->count();

        $this->discussion->post($order, new PostMessageCommand($this->plUser, 'Pouze diskuse', 'no-workflow'));

        $freshOrder = $order->fresh();
        $freshThread = $thread->fresh();
        self::assertSame($orderSnapshot, collect($orderFields)->mapWithKeys(fn (string $field): array => [$field => $freshOrder->getRawOriginal($field)])->all());
        self::assertSame($threadSnapshot, collect($threadFields)->mapWithKeys(fn (string $field): array => [$field => $freshThread->getRawOriginal($field)])->all());
        self::assertSame($eventCount, $thread->events()->count());
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

    private function realFile(string $name, string $contents, string $mime): UploadedFile
    {
        $path = $this->storageSandbox->temporaryFile('message-test');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, $mime, null, true);
    }
}
