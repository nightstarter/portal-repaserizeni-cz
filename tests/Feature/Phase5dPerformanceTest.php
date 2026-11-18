<?php

namespace Tests\Feature;

use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\UserRole;
use App\Models\Message;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase5dPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_menu_and_discussion_queue_have_constant_query_counts(): void
    {
        $ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $pl = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $viewer = User::factory()->create(['organization_id' => $ak->id, 'role' => UserRole::AK_OPERATOR]);
        $author = User::factory()->create(['organization_id' => $pl->id, 'role' => UserRole::PL_OPERATOR]);
        $viewer->load('organization');
        $this->createOrders(2, $ak, $author, 'SMALL');
        $this->actingAs($viewer);

        $smallDashboard = $this->selectQueryCount(route('dashboard'));
        $smallDiscussions = $this->selectQueryCount(route('discussions.index'));

        $this->createOrders(35, $ak, $author, 'LARGE');
        $largeDashboard = $this->selectQueryCount(route('dashboard'));
        $largeDiscussions = $this->selectQueryCount(route('discussions.index'));

        self::assertLessThanOrEqual($smallDashboard + 1, $largeDashboard, "Dashboard query count grew from {$smallDashboard} to {$largeDashboard}.");
        self::assertLessThanOrEqual($smallDiscussions + 1, $largeDiscussions, "Discussion queue query count grew from {$smallDiscussions} to {$largeDiscussions}.");
        self::assertLessThanOrEqual(25, $largeDashboard);
        self::assertLessThanOrEqual(12, $largeDiscussions);
    }

    public function test_phase_five_worker_and_history_composite_indexes_exist(): void
    {
        $messageIndexes = collect(DB::select("PRAGMA index_list('messages')"))->pluck('name');
        $outboxIndexes = collect(DB::select("PRAGMA index_list('outbox_events')"))->pluck('name');

        self::assertTrue($messageIndexes->contains('messages_order_created_id_index'));
        self::assertTrue($outboxIndexes->contains('outbox_worker_due_index'));
        self::assertTrue($outboxIndexes->contains('outbox_worker_lock_index'));
    }

    private function createOrders(int $count, Organization $responsibleOrganization, User $author, string $prefix): void
    {
        foreach (range(1, $count) as $number) {
            $order = Order::factory()->create([
                'order_no' => sprintf('5D-%s-%03d', $prefix, $number),
                'source_order_id' => sprintf('5D-%s-%03d', $prefix, $number),
            ]);
            ProcessThread::factory()->create([
                'order_id' => $order->id,
                'type' => ProcessThreadType::CUSTOM_REBUILD,
                'state' => 'RECEIVED_AK',
                'responsible_party' => ResponsibleParty::AUTOKLUBY,
                'responsible_organization_id' => $responsibleOrganization->id,
            ]);
            Message::query()->create([
                'public_token' => (string) Str::uuid(),
                'order_id' => $order->id,
                'author_user_id' => $author->id,
                'author_organization_id' => $author->organization_id,
                'body' => 'Výkonová zpráva '.$number,
                'idempotency_key' => strtolower($prefix).'-message-'.$number,
                'request_hash' => hash('sha256', $prefix.'-'.$number),
                'created_at' => now()->addSeconds($number),
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
