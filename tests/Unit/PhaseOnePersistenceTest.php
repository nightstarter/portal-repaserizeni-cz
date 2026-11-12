<?php

namespace Tests\Unit;

use App\Application\Audit\AuditLogger;
use App\Enums\OrganizationCode;
use App\Enums\UserRole;
use App\Models\IntegrationCheckpoint;
use App\Models\IntegrationEvent;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderCustomer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseOnePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_owns_customer_snapshot_and_multiple_physical_items(): void
    {
        $order = Order::factory()->create();
        $customer = OrderCustomer::factory()->for($order)->create();
        Item::factory()->count(2)->for($order)->create();

        self::assertTrue($order->customer->is($customer));
        self::assertCount(2, $order->items);
        self::assertNotSame($order->items[0]->qr_token, $order->items[1]->qr_token);
    }

    public function test_integration_event_identity_is_unique_per_integration(): void
    {
        $event = [
            'integration' => 'LEGACY_SHOP',
            'external_event_id' => 'order-100',
            'type' => 'ORDER_AVAILABLE',
            'payload_hash' => hash('sha256', '{}'),
        ];

        IntegrationEvent::query()->create($event);

        $this->expectException(QueryException::class);
        IntegrationEvent::query()->create($event);
    }

    public function test_checkpoint_stores_only_safe_cursor_metadata(): void
    {
        $checkpoint = IntegrationCheckpoint::query()->create([
            'integration' => 'LEGACY_SHOP',
            'cursor' => 'order-id:100',
            'last_processed_id' => '100',
            'metadata' => ['mode' => 'test'],
        ]);

        self::assertSame(['mode' => 'test'], $checkpoint->metadata);
        self::assertNull($checkpoint->last_processed_at);
    }

    public function test_order_audit_entry_keeps_direct_order_relation(): void
    {
        $organization = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY]);
        $user = User::factory()->create(['organization_id' => $organization->id, 'role' => UserRole::AK_OPERATOR]);
        $order = Order::factory()->create();

        $entry = app(AuditLogger::class)->record('ORDER_TESTED', $order, actor: $user);

        self::assertTrue($entry->order->is($order));
        self::assertSame($order->id, $entry->order_id);
    }
}
