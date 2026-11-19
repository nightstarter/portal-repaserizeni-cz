<?php

namespace App\Integrations\Stock;

use App\Application\Orders\StockAvailabilityDecision;
use App\Contracts\ResolvesStockAvailability;
use App\Enums\StockAvailabilityStatus;
use App\Models\Order;
use App\Models\StockSnapshotBatch;
use App\Models\StockSnapshotItem;

final class PendingStockAvailabilityResolver implements ResolvesStockAvailability
{
    public function resolve(Order $order): StockAvailabilityDecision
    {
        $snapshot = StockSnapshotItem::query()
            ->where('product_reference', $order->product_code)
            ->where('accepted', true)
            ->whereHas('batch', fn ($query) => $query->where('warehouse_code', 'DECIN'))
            ->with('batch')
            ->orderByDesc(StockSnapshotBatch::query()->select('source_generated_at')
                ->whereColumn('stock_snapshot_batches.id', 'stock_snapshot_items.stock_snapshot_batch_id')
                ->limit(1))
            ->orderByDesc('id')
            ->first();
        if ($snapshot === null) {
            return new StockAvailabilityDecision(StockAvailabilityStatus::UNKNOWN, 'NO_TRUSTED_SNAPSHOT');
        }
        $source = 'pohoda:'.$snapshot->batch->batch_id.':'.$snapshot->source_row_version;
        if ($snapshot->batch->source_generated_at->lt(now()->subMinutes(
            max(1, (int) config('integrations.pohoda_bridge.stale_minutes', 30)),
        ))) {
            return new StockAvailabilityDecision(StockAvailabilityStatus::STALE, $source, $snapshot->batch->source_generated_at);
        }

        return new StockAvailabilityDecision(
            $snapshot->available_quantity > 0
                ? StockAvailabilityStatus::AVAILABLE_IN_AK
                : StockAvailabilityStatus::NOT_AVAILABLE_IN_AK,
            $source,
            $snapshot->batch->source_generated_at,
        );
    }
}
