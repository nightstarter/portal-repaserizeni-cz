<?php

namespace App\Integrations\Stock;

use App\Application\Orders\StockAvailabilityDecision;
use App\Contracts\ResolvesStockAvailability;
use App\Enums\StockAvailabilityStatus;
use App\Models\Order;
use App\Models\StockSnapshotBatch;
use App\Models\StockSnapshotItem;
use App\Models\StockSnapshotRun;

final class PendingStockAvailabilityResolver implements ResolvesStockAvailability
{
    public function resolve(Order $order): StockAvailabilityDecision
    {
        $run = StockSnapshotRun::query()
            ->where('warehouse_code', 'DECIN')
            ->whereIn('status', [StockSnapshotRun::COMPLETE, StockSnapshotRun::PARTIAL])
            ->orderByDesc('source_generated_at')->orderByDesc('id')->first();
        if ($run !== null) {
            $source = 'pohoda:'.$run->run_id;
            if ($run->source_generated_at->lt(now()->subMinutes(
                max(1, (int) config('integrations.pohoda_bridge.stale_minutes', 30)),
            ))) {
                return new StockAvailabilityDecision(StockAvailabilityStatus::STALE, $source, $run->source_generated_at);
            }
            $snapshot = StockSnapshotItem::query()
                ->where('product_reference', $order->product_code)
                ->where('accepted', true)
                ->whereHas('batch', fn ($query) => $query->where('stock_snapshot_run_id', $run->id))
                ->orderByDesc('id')->first();
            if ($snapshot === null) {
                return new StockAvailabilityDecision(
                    $run->status === StockSnapshotRun::COMPLETE
                        ? StockAvailabilityStatus::NOT_AVAILABLE_IN_AK
                        : StockAvailabilityStatus::UNKNOWN,
                    $source,
                    $run->source_generated_at,
                );
            }

            return new StockAvailabilityDecision(
                $snapshot->available_quantity > 0
                    ? StockAvailabilityStatus::AVAILABLE_IN_AK
                    : StockAvailabilityStatus::NOT_AVAILABLE_IN_AK,
                $source.':'.$snapshot->source_row_version,
                $run->source_generated_at,
            );
        }

        // Backward-compatible fallback for the pre-chunk 7A records.
        $snapshot = StockSnapshotItem::query()
            ->where('product_reference', $order->product_code)
            ->where('accepted', true)
            ->whereHas('batch', fn ($query) => $query->where('warehouse_code', 'DECIN')->whereNull('stock_snapshot_run_id'))
            ->with('batch')
            ->orderByDesc(StockSnapshotBatch::query()->select('source_generated_at')
                ->whereColumn('stock_snapshot_batches.id', 'stock_snapshot_items.stock_snapshot_batch_id')
                ->limit(1))
            ->orderByDesc('id')
            ->first();
        if ($snapshot === null) {
            return new StockAvailabilityDecision(StockAvailabilityStatus::UNKNOWN, 'NO_TRUSTED_SNAPSHOT');
        }
        $sourceAt = $snapshot->batch->source_generated_at;
        $source = 'pohoda:'.$snapshot->batch->batch_id.':'.$snapshot->source_row_version;
        if ($sourceAt->lt(now()->subMinutes(
            max(1, (int) config('integrations.pohoda_bridge.stale_minutes', 30)),
        ))) {
            return new StockAvailabilityDecision(StockAvailabilityStatus::STALE, $source, $sourceAt);
        }

        return new StockAvailabilityDecision(
            $snapshot->available_quantity > 0
                ? StockAvailabilityStatus::AVAILABLE_IN_AK
                : StockAvailabilityStatus::NOT_AVAILABLE_IN_AK,
            $source,
            $sourceAt,
        );
    }
}
