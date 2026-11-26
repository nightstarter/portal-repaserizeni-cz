<?php

namespace App\Integrations\LegacyShop;

use App\Application\Audit\AuditLogger;
use App\Enums\OrderSource;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class LegacyOrderNumberRepairService
{
    public const DRY_RUN = 'DRY_RUN';

    public const REPAIRED = 'REPAIRED';

    public const ALREADY_CORRECT = 'ALREADY_CORRECT';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function run(string $sourceOrderId, string $expectedOldOrderNo, bool $apply): string
    {
        if (preg_match('/^[1-9][0-9]*$/D', $sourceOrderId) !== 1) {
            throw new LegacyShopConfigurationException('The source order ID must be a positive integer.');
        }
        if ($expectedOldOrderNo === '') {
            throw new LegacyShopConfigurationException('The expected old order number is required.');
        }

        return DB::transaction(function () use ($sourceOrderId, $expectedOldOrderNo, $apply): string {
            $order = Order::query()
                ->where('source', OrderSource::ESHOP)
                ->where('source_order_id', $sourceOrderId)
                ->lockForUpdate()
                ->first();
            if ($order === null) {
                throw new LegacyShopConfigurationException('The requested legacy shop order was not found.');
            }

            $targetOrderNo = $sourceOrderId;
            if ($order->order_no === $targetOrderNo) {
                return self::ALREADY_CORRECT;
            }
            if ($order->order_no !== $expectedOldOrderNo) {
                throw new LegacyShopConfigurationException('The current order number does not match the expected old value.');
            }

            $collision = Order::query()
                ->where('order_no', $targetOrderNo)
                ->whereKeyNot($order->getKey())
                ->lockForUpdate()
                ->exists();
            if ($collision) {
                throw new LegacyShopConfigurationException('The target order number is already in use.');
            }
            if (! $apply) {
                return self::DRY_RUN;
            }

            $oldOrderNo = $order->order_no;
            $order->update(['order_no' => $targetOrderNo]);
            $this->auditLogger->record(
                'LEGACY_ORDER_NUMBER_REPAIRED',
                $order,
                ['order_no' => $oldOrderNo, 'source_order_id' => $sourceOrderId],
                ['order_no' => $targetOrderNo, 'source_order_id' => $sourceOrderId],
                'Confirmed legacy e-shop order number correction.',
            );

            return self::REPAIRED;
        });
    }
}
