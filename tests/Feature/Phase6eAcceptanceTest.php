<?php

namespace Tests\Feature;

use App\Domain\Messages\MessagePosted;
use App\Enums\OrganizationCode;
use App\Enums\UserRole;
use App\Models\Message;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase6eAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_six_queue_and_history_indexes_exist(): void
    {
        $threadIndexes = collect(DB::select("PRAGMA index_list('process_threads')"))->pluck('name');
        $quoteIndexes = collect(DB::select("PRAGMA index_list('buyback_price_quotes')"))->pluck('name');
        $outboxIndexes = collect(DB::select("PRAGMA index_list('outbox_events')"))->pluck('name');

        self::assertTrue($threadIndexes->contains('threads_warranty_cycle_unique'));
        self::assertTrue($threadIndexes->contains('threads_warranty_active_index'));
        self::assertTrue($quoteIndexes->contains('buyback_quote_version_unique'));
        self::assertTrue($quoteIndexes->contains('buyback_quote_history_index'));
        self::assertTrue($outboxIndexes->contains('outbox_worker_due_index'));
        self::assertTrue($outboxIndexes->contains('outbox_worker_lock_index'));
        self::assertSame(['year'], collect(DB::select("PRAGMA table_info('buyback_order_sequences')"))
            ->filter(fn (object $column): bool => (bool) $column->pk)->pluck('name')->all());

        $foreignColumns = collect(DB::select("PRAGMA foreign_key_list('process_threads')"))->pluck('from');
        self::assertTrue($foreignColumns->contains('item_id'));
        self::assertTrue($foreignColumns->contains('created_by'));
    }

    public function test_notification_admin_query_count_is_constant_for_one_hundred_events(): void
    {
        $organization = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY]);
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => UserRole::AK_ADMIN,
            'email' => 'phase6e-admin@example.test',
        ]);
        $order = Order::factory()->create(['order_no' => 'PHASE-6E-ADMIN', 'source_order_id' => 'PHASE-6E-ADMIN']);
        $message = Message::query()->create([
            'public_token' => (string) Str::uuid(),
            'order_id' => $order->id,
            'author_user_id' => $admin->id,
            'author_organization_id' => $organization->id,
            'body' => 'Performance fixture.',
            'idempotency_key' => 'phase6e-admin-message',
            'request_hash' => hash('sha256', 'phase6e-admin-message'),
        ]);
        $this->createEvents($message, $admin, 2, 1);
        $this->actingAs($admin);
        $small = $this->selectQueryCount(route('admin.notifications.index'));

        $this->createEvents($message, $admin, 98, 3);
        $large = $this->selectQueryCount(route('admin.notifications.index'));

        self::assertLessThanOrEqual($small + 1, $large, "Notification admin queries grew from {$small} to {$large}.");
        self::assertLessThanOrEqual(12, $large);
    }

    private function createEvents(Message $message, User $recipient, int $count, int $start): void
    {
        foreach (range($start, $start + $count - 1) as $number) {
            OutboxEvent::query()->create([
                'event_uuid' => (string) Str::uuid(),
                'event_type' => MessagePosted::TYPE,
                'aggregate_type' => Message::class,
                'aggregate_id' => $message->id,
                'recipient_user_id' => $recipient->id,
                'deduplication_key' => 'phase6e-admin-event-'.$number,
                'payload' => ['message_id' => $message->id],
                'status' => OutboxEvent::PENDING,
                'available_at' => now(),
                'next_attempt_at' => now(),
            ]);
        }
    }

    private function selectQueryCount(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $count = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select'))
            ->count();
        DB::disableQueryLog();

        return $count;
    }
}
