<?php

namespace Tests\Feature;

use App\Application\Messages\DiscussionService;
use App\Application\Messages\PostMessageCommand;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\UserRole;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderCustomer;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\IsolatedStorageSandbox;
use Tests\TestCase;

class Phase5bDiscussionUiTest extends TestCase
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
            ['root' => $this->storageSandbox->diskRoot('phase5b-private')],
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
    }

    protected function tearDown(): void
    {
        try {
            $this->storageSandbox?->cleanup();
        } finally {
            parent::tearDown();
        }
    }

    public function test_discussion_ui_posts_text_and_multiple_private_attachments_idempotently(): void
    {
        [$order] = $this->visibleOrder('5B-POST');
        $payload = [
            'body' => 'Dvě bezpečné přílohy.',
            'idempotency_key' => '5b-double-submit',
            'attachments' => [$this->validPdf('prvni.pdf'), $this->validPdf('druhy-dlouhy-nazev-souboru.pdf')],
        ];
        $this->actingAs($this->akUser)->post(route('orders.discussion.store', $order), $payload)
            ->assertRedirect(route('orders.discussion.index', $order));
        $this->actingAs($this->akUser)->post(route('orders.discussion.store', $order), [
            'body' => 'Dvě bezpečné přílohy.',
            'idempotency_key' => '5b-double-submit',
            'attachments' => [$this->validPdf('prvni.pdf'), $this->validPdf('druhy-dlouhy-nazev-souboru.pdf')],
        ])->assertRedirect(route('orders.discussion.index', $order));

        self::assertSame(1, Message::query()->where('idempotency_key', '5b-double-submit')->count());
        $posted = Message::query()->where('idempotency_key', '5b-double-submit')->sole();
        self::assertSame(2, $posted->attachments()->count());
        self::assertCount(2, Storage::disk('local')->allFiles('message-attachments'));
        self::assertSame([], Storage::disk('public')->allFiles());
        $this->discussion->post($order, new PostMessageCommand($this->plUser, 'Odpověď Polska.', '5b-polish-style'));

        $page = $this->actingAs($this->akUser)->get(route('orders.discussion.index', $order))->assertOk()
            ->assertSee('Dvě bezpečné přílohy.')
            ->assertSee('prvni.pdf')
            ->assertSee('druhy-dlouhy-nazev-souboru.pdf')
            ->assertSee('organization-autokluby', false)
            ->assertSee('organization-poland', false)
            ->assertSee('data-submit-once', false)
            ->assertSee("form.dataset.submitted === '1'", false)
            ->assertSee('event.preventDefault()', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="attachments[]" multiple', false);
        foreach ($posted->attachments as $attachment) {
            $page->assertSee(route('message-attachments.download', $attachment), false)
                ->assertDontSee($attachment->storage_path);
        }
    }

    public function test_empty_too_long_and_invalid_attachment_errors_preserve_body(): void
    {
        [$order] = $this->visibleOrder('5B-VALIDATION');
        $this->actingAs($this->akUser)->from(route('orders.discussion.index', $order))->post(route('orders.discussion.store', $order), [
            'body' => ' ',
            'idempotency_key' => '5b-empty',
        ])->assertRedirect(route('orders.discussion.index', $order))->assertSessionHasErrors('body');
        $this->actingAs($this->akUser)->from(route('orders.discussion.index', $order))->post(route('orders.discussion.store', $order), [
            'body' => str_repeat('x', 10001),
            'idempotency_key' => '5b-long',
        ])->assertRedirect(route('orders.discussion.index', $order))->assertSessionHasErrors('body');
        $this->actingAs($this->akUser)->from(route('orders.discussion.index', $order))->post(route('orders.discussion.store', $order), [
            'body' => 'Tento text musí zůstat po chybě.',
            'idempotency_key' => '5b-old-input',
            'attachments' => [UploadedFile::fake()->createWithContent('active.svg', '<svg onload="alert(1)"></svg>')],
        ])->assertRedirect(route('orders.discussion.index', $order))
            ->assertSessionHasErrors('attachments')
            ->assertSessionHasInput('body', 'Tento text musí zůstat po chybě.');

        self::assertSame(0, Message::query()->count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_message_and_queue_escape_xss_instead_of_rendering_active_html(): void
    {
        [$order] = $this->visibleOrder('5B-XSS');
        $xss = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
        $this->discussion->post($order, new PostMessageCommand($this->akUser, $xss, '5b-xss'));

        foreach ([route('orders.discussion.index', $order), route('discussions.index')] as $url) {
            $this->actingAs($this->plUser)->get($url)->assertOk()
                ->assertSee('&lt;script&gt;', false)
                ->assertDontSee('<script>alert', false)
                ->assertDontSee('<img src=x', false);
        }
    }

    public function test_foreign_urls_are_404_read_only_cannot_post_and_poland_sees_no_customer_pii(): void
    {
        [$visible] = $this->visibleOrder('5B-VISIBLE');
        [$hidden] = $this->hiddenOrder('5B-HIDDEN');
        OrderCustomer::factory()->for($visible)->create([
            'name' => 'Soukromý zákazník 5B',
            'email' => 'private-5b@example.test',
            'phone' => '+420123987456',
        ]);
        $visibleMessage = $this->discussion->post($visible, new PostMessageCommand($this->akUser, 'Bezpečný provozní text', '5b-visible', [$this->validPdf('visible.pdf')]));
        $hiddenMessage = $this->discussion->post($hidden, new PostMessageCommand($this->akUser, 'Cizí zpráva', '5b-hidden', [$this->validPdf('hidden.pdf')]));

        $this->actingAs($this->plUser)->get(route('orders.discussion.index', $visible))->assertOk()
            ->assertSee($visibleMessage->body)
            ->assertDontSee('Soukromý zákazník 5B')
            ->assertDontSee('private-5b@example.test')
            ->assertDontSee('+420123987456');
        $this->actingAs($this->plUser)->get(route('orders.discussion.index', $hidden))->assertNotFound();
        $this->actingAs($this->plUser)->post(route('orders.discussion.store', $hidden), [
            'body' => 'Cizí zápis', 'idempotency_key' => '5b-foreign-post',
        ])->assertNotFound();
        $this->actingAs($this->plUser)->get(route('message-attachments.download', $hiddenMessage->attachments->sole()))->assertNotFound();

        $readOnly = User::factory()->create(['organization_id' => $this->pl->id, 'role' => UserRole::READ_ONLY, 'preferred_locale' => 'pl']);
        $this->actingAs($readOnly)->get(route('orders.discussion.index', $visible))->assertOk()->assertDontSee('Napisz wiadomość');
        $this->actingAs($readOnly)->post(route('orders.discussion.store', $visible), [
            'body' => 'Zakázaný zápis', 'idempotency_key' => '5b-read-only',
        ])->assertForbidden()->assertSee('Nie masz uprawnień');
    }

    public function test_opening_discussion_marks_messages_read_and_updates_global_and_local_badges(): void
    {
        [$order] = $this->visibleOrder('5B-BADGES');
        [$hiddenOrder] = $this->hiddenOrder('5B-BADGES-HIDDEN');
        $this->discussion->post($order, new PostMessageCommand($this->akUser, 'Nepřečtená zpráva', '5b-unread'));
        $this->discussion->post($hiddenOrder, new PostMessageCommand($this->akUser, 'Cizí nepřečtená zpráva', '5b-hidden-unread'));
        self::assertSame(1, $this->discussion->globalUnreadCount($this->plUser));

        $detail = $this->actingAs($this->plUser)->get(route('orders.show', $order))->assertOk();
        $detail->assertSee(route('discussions.index'), false)
            ->assertSee('class="unread-badge"', false)
            ->assertSee('>1</span>', false);

        $this->actingAs($this->plUser)->get(route('orders.discussion.index', $order))->assertOk();
        self::assertSame(0, $this->discussion->unreadCount($order, $this->plUser));
        self::assertSame(0, $this->discussion->globalUnreadCount($this->plUser));
        $visibleMessage = Message::query()->where('order_id', $order->id)->sole();
        self::assertSame(1, $visibleMessage->reads()->where('user_id', $this->plUser->id)->count());
        $this->actingAs($this->plUser)->post(route('orders.discussion.read', $order))->assertRedirect();
        self::assertSame(1, $visibleMessage->reads()->where('user_id', $this->plUser->id)->count());

        $this->actingAs($this->plUser)->get(route('orders.show', $order))->assertOk()->assertDontSee('class="unread-badge"', false);
    }

    public function test_discussion_queue_is_row_scoped_unread_first_and_has_clear_empty_state(): void
    {
        [$unreadOrder] = $this->visibleOrder('5B-QUEUE-UNREAD');
        [$readOrder] = $this->visibleOrder('5B-QUEUE-READ');
        [$hiddenOrder] = $this->hiddenOrder('5B-QUEUE-HIDDEN');
        $this->travelTo('2026-11-18 10:00:00');
        $this->discussion->post($unreadOrder, new PostMessageCommand($this->akUser, 'Starší, ale nepřečtená', '5b-queue-unread'));
        $this->travelTo('2026-11-18 11:00:00');
        $this->discussion->post($readOrder, new PostMessageCommand($this->plUser, 'Novější vlastní zpráva', '5b-queue-read'));
        $this->discussion->post($hiddenOrder, new PostMessageCommand($this->akUser, 'Polsku skrytá', '5b-queue-hidden'));
        $this->travelBack();

        $response = $this->actingAs($this->plUser)->get(route('discussions.index'))->assertOk()
            ->assertSee('5B-QUEUE-UNREAD')
            ->assertSee('5B-QUEUE-READ')
            ->assertDontSee('5B-QUEUE-HIDDEN')
            ->assertDontSee('Polsku skrytá')
            ->assertSee('<a class="active" href="'.route('discussions.index').'"', false);
        $response->assertSeeInOrder(['5B-QUEUE-UNREAD', '5B-QUEUE-READ']);

    }

    public function test_cursor_pagination_loads_every_message_once_in_deterministic_order(): void
    {
        [$order] = $this->visibleOrder('5B-PAGES');
        $this->travelTo('2026-11-18 12:00:00');
        foreach (range(1, 65) as $number) {
            $label = 'PAGE-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            $this->discussion->post($order, new PostMessageCommand($this->akUser, $label, '5b-page-'.$number));
        }
        $this->travelBack();

        $first = $this->actingAs($this->plUser)->get(route('orders.discussion.index', $order))->assertOk()
            ->assertSee('PAGE-036')->assertSee('PAGE-065')->assertDontSee('PAGE-035')->assertSee('Wczytaj starsze');
        $firstPaginator = $first->viewData('messages');
        $second = $this->actingAs($this->plUser)->get($firstPaginator->nextPageUrl())->assertOk()
            ->assertSee('PAGE-006')->assertSee('PAGE-035')->assertDontSee('PAGE-036')->assertDontSee('PAGE-005');
        $secondPaginator = $second->viewData('messages');
        $third = $this->actingAs($this->plUser)->get($secondPaginator->nextPageUrl())->assertOk()
            ->assertSee('PAGE-001')->assertSee('PAGE-005')->assertDontSee('PAGE-006');

        self::assertCount(30, $firstPaginator->items());
        self::assertCount(30, $secondPaginator->items());
        self::assertCount(5, $third->viewData('messages')->items());
        self::assertSame(65, Message::query()->count());
    }

    public function test_empty_discussion_and_complete_czech_polish_labels_are_visible(): void
    {
        [$order] = $this->visibleOrder('5B-I18N');
        $this->actingAs($this->plUser)->get(route('discussions.index'))->assertOk()->assertSee('Nie ma jeszcze żadnych dyskusji.');
        $this->actingAs($this->akUser)->get(route('orders.discussion.index', $order))->assertOk()
            ->assertSee('Zatím zde nejsou žádné zprávy.')
            ->assertSee('Napsat zprávu')
            ->assertSee('Přiložit soubor')
            ->assertSee('Odeslat');
        $this->actingAs($this->plUser)->get(route('orders.discussion.index', $order))->assertOk()
            ->assertSee('Nie ma jeszcze żadnych wiadomości.')
            ->assertSee('Napisz wiadomość')
            ->assertSee('Dołącz plik')
            ->assertSee('Wyślij');
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
