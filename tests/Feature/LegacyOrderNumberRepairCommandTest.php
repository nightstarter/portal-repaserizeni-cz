<?php

namespace Tests\Feature;

use App\Enums\OrderSource;
use App\Models\AuditLog;
use App\Models\IntegrationCheckpoint;
use App\Models\IntegrationEvent;
use App\Models\Item;
use App\Models\Order;
use App\Models\ProcessThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class LegacyOrderNumberRepairCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_mode_is_dry_run_without_writes(): void
    {
        $order = $this->legacyOrder();

        $this->artisan('legacy-shop:repair-order-number', [
            'source-order-id' => '665',
            '--expected-old-order-no' => '4666',
        ])->assertSuccessful()->expectsOutputToContain('DRY_RUN');

        self::assertSame('4666', $order->fresh()->order_no);
        $this->assertDatabaseCount('audit_log', 0);
    }

    public function test_apply_repairs_only_order_number_and_creates_audit(): void
    {
        $order = $this->legacyOrder();
        $snapshot = $this->unchangedSnapshot($order);
        $item = Item::factory()->create(['order_id' => $order->id]);
        $thread = ProcessThread::factory()->create([
            'order_id' => $order->id,
            'item_id' => $item->id,
        ]);
        $event = IntegrationEvent::query()->create([
            'integration' => 'legacy_shop',
            'external_event_id' => 'order:665',
            'type' => 'LEGACY_ORDER_IMPORTED',
            'payload_hash' => hash('sha256', 'fixture'),
            'payload' => ['source_order_id' => '665'],
            'status' => 'PROCESSED',
        ]);
        $checkpoint = IntegrationCheckpoint::query()->create([
            'integration' => 'legacy_shop',
            'cursor' => '665',
            'last_processed_id' => '665',
            'metadata' => ['fixture' => true],
        ]);
        $item->refresh();
        $thread->refresh();
        $event->refresh();
        $checkpoint->refresh();
        $relatedSnapshots = [
            'item' => $item->getRawOriginal(),
            'thread' => $thread->getRawOriginal(),
            'event' => $event->getRawOriginal(),
            'checkpoint' => $checkpoint->getRawOriginal(),
        ];

        $this->artisan('legacy-shop:repair-order-number', [
            'source-order-id' => '665',
            '--expected-old-order-no' => '4666',
            '--apply' => true,
        ])->assertSuccessful()->expectsOutputToContain('REPAIRED');

        $order->refresh();
        self::assertSame('665', $order->order_no);
        self::assertSame($snapshot, $this->unchangedSnapshot($order));
        self::assertSame($relatedSnapshots['item'], $item->fresh()->getRawOriginal());
        self::assertSame($relatedSnapshots['thread'], $thread->fresh()->getRawOriginal());
        self::assertSame($relatedSnapshots['event'], $event->fresh()->getRawOriginal());
        self::assertSame($relatedSnapshots['checkpoint'], $checkpoint->fresh()->getRawOriginal());
        $audit = AuditLog::query()->sole();
        self::assertSame('LEGACY_ORDER_NUMBER_REPAIRED', $audit->action);
        self::assertSame(['order_no' => '4666', 'source_order_id' => '665'], $audit->old_values);
        self::assertSame(['order_no' => '665', 'source_order_id' => '665'], $audit->new_values);
    }

    public function test_repeated_apply_is_idempotent_without_second_audit(): void
    {
        $order = $this->legacyOrder();
        $arguments = [
            'source-order-id' => '665',
            '--expected-old-order-no' => '4666',
            '--apply' => true,
        ];
        $this->artisan('legacy-shop:repair-order-number', $arguments)->assertSuccessful();

        $this->artisan('legacy-shop:repair-order-number', $arguments)
            ->assertSuccessful()
            ->expectsOutputToContain('ALREADY_CORRECT');

        self::assertSame('665', $order->fresh()->order_no);
        $this->assertDatabaseCount('audit_log', 1);
    }

    public function test_apply_refuses_target_collision_without_writes(): void
    {
        $order = $this->legacyOrder();
        Order::factory()->buyout()->create(['order_no' => '665']);

        $this->artisan('legacy-shop:repair-order-number', [
            'source-order-id' => '665',
            '--expected-old-order-no' => '4666',
            '--apply' => true,
        ])->assertFailed()->expectsOutputToContain('already in use');

        self::assertSame('4666', $order->fresh()->order_no);
        $this->assertDatabaseCount('audit_log', 0);
    }

    public function test_apply_rolls_back_order_number_when_audit_fails(): void
    {
        $order = $this->legacyOrder();
        Event::listen('eloquent.creating: '.AuditLog::class, static function (): never {
            throw new RuntimeException('fixture audit failure');
        });

        try {
            $this->artisan('legacy-shop:repair-order-number', [
                'source-order-id' => '665',
                '--expected-old-order-no' => '4666',
                '--apply' => true,
            ])->assertFailed()->expectsOutputToContain('RuntimeException');
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }

        self::assertSame('4666', $order->fresh()->order_no);
        $this->assertDatabaseCount('audit_log', 0);
    }

    public function test_command_rejects_wrong_expected_value_and_non_eshop_source(): void
    {
        $order = $this->legacyOrder();
        $this->artisan('legacy-shop:repair-order-number', [
            'source-order-id' => '665',
            '--expected-old-order-no' => 'wrong',
            '--apply' => true,
        ])->assertFailed()->expectsOutputToContain('does not match');
        self::assertSame('4666', $order->fresh()->order_no);

        $order->update(['source' => OrderSource::SYSTEM_BUYOUT]);
        $this->artisan('legacy-shop:repair-order-number', [
            'source-order-id' => '665',
            '--expected-old-order-no' => '4666',
            '--apply' => true,
        ])->assertFailed()->expectsOutputToContain('not found');
        self::assertSame('4666', $order->fresh()->order_no);
        $this->assertDatabaseCount('audit_log', 0);
    }

    private function legacyOrder(): Order
    {
        return Order::factory()->create([
            'source' => OrderSource::ESHOP,
            'source_order_id' => '665',
            'order_no' => '4666',
        ]);
    }

    /** @return array<string, mixed> */
    private function unchangedSnapshot(Order $order): array
    {
        $attributes = [
            'source', 'source_order_id', 'order_type', 'technical_type', 'routing',
            'product_code', 'product_name_snapshot', 'vin', 'vehicle', 'customer_handover',
            'product_verification_status', 'business_snapshot', 'imported_at', 'closed_at',
        ];

        return array_combine(
            $attributes,
            array_map(fn (string $attribute): mixed => $order->getRawOriginal($attribute), $attributes),
        );
    }
}
