<?php

namespace Tests\Feature;

use App\Application\Orders\BuybackOrderService;
use App\Application\Orders\CreateBuybackCommand;
use App\Enums\BuybackPriceType;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\FinanceEntryStatus;
use App\Enums\FinanceEntryType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ShipmentCarrier;
use App\Enums\ShipmentDirection;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\BuybackPriceQuote;
use App\Models\Document;
use App\Models\FinanceEntry;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase6dBuybackPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_menu_queue_and_rich_detail_have_constant_query_counts(): void
    {
        $ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY]);
        $pl = Organization::factory()->create(['code' => OrganizationCode::POLAND]);
        $viewer = User::factory()->create(['organization_id' => $ak->id, 'role' => UserRole::AK_ADMIN]);
        $service = app(BuybackOrderService::class);
        $first = $service->create(new CreateBuybackCommand($viewer, 'perf-1', $this->data(1)));
        $service->create(new CreateBuybackCommand($viewer, 'perf-2', $this->data(2)));
        $thread = $first->processThreads()->where('type', ProcessThreadType::BUYOUT)->sole();
        $this->actingAs($viewer);

        $smallDashboard = $this->selectQueryCount(route('dashboard'));
        $smallQueue = $this->selectQueryCount(route('buybacks.index'));
        $smallDetail = $this->selectQueryCount(route('orders.show', $first));

        foreach (range(3, 100) as $number) {
            $service->create(new CreateBuybackCommand($viewer, 'perf-'.$number, $this->data($number)));
        }
        foreach (range(1, 30) as $version) {
            BuybackPriceQuote::query()->create([
                'order_id' => $first->id, 'process_thread_id' => $thread->id,
                'type' => BuybackPriceType::POLAND_OFFER, 'amount_minor' => 10000 + $version,
                'currency' => 'EUR', 'version' => $version, 'actor_user_id' => $viewer->id,
                'actor_organization_id' => $ak->id, 'idempotency_key' => 'perf-quote-'.$version,
                'request_hash' => hash('sha256', 'perf-quote-'.$version),
            ]);
            $shipment = Shipment::query()->create([
                'direction' => ShipmentDirection::AK_TO_PL, 'carrier' => ShipmentCarrier::GLS,
                'status' => ShipmentStatus::DELIVERED, 'sender_organization_id' => $ak->id,
                'recipient_organization_id' => $pl->id, 'created_by' => $viewer->id,
            ]);
            ShipmentItem::query()->create(['shipment_id' => $shipment->id, 'item_id' => $thread->item_id, 'process_thread_id' => $thread->id]);
            Document::query()->create([
                'public_token' => (string) Str::uuid(), 'order_id' => $first->id, 'process_thread_id' => $thread->id,
                'type' => DocumentType::BUYBACK_INVOICE_TO_POLAND, 'storage_disk' => 'documents',
                'storage_name' => 'performance/'.$version.'.pdf', 'original_name' => 'invoice-'.$version.'.pdf',
                'mime' => 'application/pdf', 'extension' => 'pdf', 'size' => 20,
                'checksum_sha256' => hash('sha256', (string) $version), 'document_number' => 'PERF-'.$version,
                'document_date' => now()->toDateString(), 'amount' => '100.00', 'currency' => 'EUR',
                'uploaded_by' => $viewer->id, 'uploaded_organization_id' => $ak->id,
                'status' => DocumentStatus::INVALIDATED,
            ]);
        }
        $auditId = DB::table('audit_log')->insertGetId([
            'actor_user_id' => $viewer->id, 'organization_id' => $ak->id,
            'subject_type' => get_class($thread), 'subject_id' => $thread->id,
            'action' => 'PERFORMANCE_FIXTURE', 'created_at' => now(),
        ]);
        foreach (range(1, 30) as $version) {
            FinanceEntry::query()->create([
                'order_id' => $first->id, 'process_thread_id' => $thread->id, 'audit_log_id' => $auditId,
                'type' => FinanceEntryType::BUYBACK_POLAND_PAYMENT, 'amount' => '100.00', 'currency' => 'EUR',
                'status' => FinanceEntryStatus::SETTLED, 'actor_user_id' => $viewer->id,
                'actor_organization_id' => $ak->id, 'idempotency_key' => 'perf-finance-'.$version,
                'request_hash' => hash('sha256', 'perf-finance-'.$version),
            ]);
        }

        $largeDashboard = $this->selectQueryCount(route('dashboard'));
        $largeQueue = $this->selectQueryCount(route('buybacks.index'));
        $largeDetail = $this->selectQueryCount(route('orders.show', $first));

        self::assertLessThanOrEqual($smallDashboard + 1, $largeDashboard, "Dashboard/menu queries grew from {$smallDashboard} to {$largeDashboard}.");
        self::assertLessThanOrEqual($smallQueue + 1, $largeQueue, "Buyback queue queries grew from {$smallQueue} to {$largeQueue}.");
        self::assertLessThanOrEqual($smallDetail + 1, $largeDetail, "Rich buyback detail queries grew from {$smallDetail} to {$largeDetail}.");
        self::assertLessThanOrEqual(28, $largeDashboard);
        self::assertLessThanOrEqual(18, $largeQueue);
        self::assertLessThanOrEqual(22, $largeDetail);
    }

    private function data(int $number): array
    {
        return [
            'customer_name' => 'Performance Customer '.$number,
            'customer_email' => 'performance'.$number.'@example.test',
            'product_code' => 'PERF-'.$number,
            'internal_offer_minor' => 100000 + $number,
            'internal_offer_currency' => 'CZK',
        ];
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
