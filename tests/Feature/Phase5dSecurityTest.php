<?php

namespace Tests\Feature;

use App\Application\Messages\DiscussionNotificationDispatcher;
use App\Application\Messages\DiscussionService;
use App\Application\Messages\PostMessageCommand;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\UserRole;
use App\Models\Message;
use App\Models\Order;
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

class Phase5dSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ak;

    private Organization $pl;

    private User $akUser;

    private User $plUser;

    private ?IsolatedStorageSandbox $storageSandbox = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageSandbox = IsolatedStorageSandbox::create();
        Storage::set('local', Storage::build(array_replace(
            config('filesystems.disks.local'),
            ['root' => $this->storageSandbox->diskRoot('phase5d-private')],
        )));
        $this->ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY]);
        $this->pl = Organization::factory()->create(['code' => OrganizationCode::POLAND]);
        $this->akUser = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::AK_OPERATOR]);
        $this->plUser = User::factory()->create(['organization_id' => $this->pl->id, 'role' => UserRole::PL_OPERATOR]);
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

    public function test_html_svg_php_and_executable_attachments_are_rejected_and_filename_xss_is_sanitized(): void
    {
        $order = $this->visibleOrder('5D-FILES');
        foreach ([
            'active.html' => '<script>alert(1)</script>',
            'active.svg' => '<svg onload="alert(1)"></svg>',
            'active.php' => '<?php echo 1;',
            'active.sh' => '#!/bin/sh',
        ] as $name => $contents) {
            $this->actingAs($this->akUser)->post(route('orders.discussion.store', $order), [
                'body' => 'Zakázaná příloha',
                'idempotency_key' => '5d-reject-'.$name,
                'attachments' => [UploadedFile::fake()->createWithContent($name, $contents)],
            ])->assertSessionHasErrors('attachments');
        }
        self::assertSame(0, Message::query()->count());
        self::assertSame([], Storage::disk('local')->allFiles());

        $message = app(DiscussionService::class)->post($order, new PostMessageCommand(
            $this->akUser,
            'Bezpečný dokument',
            '5d-safe-name',
            [UploadedFile::fake()->createWithContent('<img src=x onerror=alert(1)>.pdf', "%PDF-1.4\n%%EOF")],
        ));
        $attachment = $message->attachments->sole();
        self::assertStringNotContainsString('<', $attachment->original_name);
        self::assertStringNotContainsString('>', $attachment->original_name);
        $page = $this->actingAs($this->plUser)->get(route('orders.discussion.index', $order))->assertOk();
        $page->assertDontSee('<img src=x', false)
            ->assertDontSee($attachment->storage_path);
        $download = $this->actingAs($this->plUser)->get(route('message-attachments.download', $attachment))
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff');
        self::assertStringContainsString('private', (string) $download->headers->get('cache-control'));
        self::assertStringContainsString('no-store', (string) $download->headers->get('cache-control'));
    }

    public function test_smtp_exception_secret_is_absent_from_outbox_audit_and_admin_html(): void
    {
        config()->set('discussion_notifications.enabled', true);
        $order = $this->visibleOrder('5D-SECRETS');
        app(DiscussionService::class)->post($order, new PostMessageCommand($this->akUser, 'Provozní text', '5d-secret'));
        $canary = 'SMTP_CREDENTIAL_CANARY_5D';
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('password='.$canary));

        app(DiscussionNotificationDispatcher::class)->dispatch(1);
        $event = OutboxEvent::query()->sole();
        self::assertStringNotContainsString($canary, (string) $event->last_error);
        self::assertStringNotContainsString($canary, json_encode($event->payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($canary, DB::table('audit_log')->get()->toJson());

        $admin = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::AK_ADMIN]);
        $this->actingAs($admin)->get(route('admin.notifications.index'))->assertOk()->assertDontSee($canary);
    }

    private function visibleOrder(string $number): Order
    {
        $order = Order::factory()->create(['order_no' => $number, 'source_order_id' => $number]);
        ProcessThread::factory()->create([
            'order_id' => $order->id,
            'type' => ProcessThreadType::CUSTOM_REBUILD,
            'state' => 'SENT_TO_PL',
            'responsible_party' => ResponsibleParty::POLAND,
            'responsible_organization_id' => $this->pl->id,
        ]);

        return $order;
    }
}
