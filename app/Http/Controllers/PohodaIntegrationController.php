<?php

namespace App\Http\Controllers;

use App\Models\BridgeCommand;
use App\Models\BridgeHeartbeat;
use App\Models\StockSnapshotConfirmation;
use App\Models\StockSnapshotItem;
use App\Models\StockSnapshotRun;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PohodaIntegrationController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless($request->user()->isAdministrator()
            && $request->user()->organization->code->value === 'AUTOKLUBY', 404);
        $heartbeat = BridgeHeartbeat::query()->latest('occurred_at')->first();
        $complete = StockSnapshotRun::query()->where('status', StockSnapshotRun::COMPLETE)->latest('source_generated_at')->first();
        $partial = StockSnapshotRun::query()->where('status', StockSnapshotRun::PARTIAL)->latest('source_generated_at')->first();
        $latest = StockSnapshotRun::query()->whereIn('status', [StockSnapshotRun::COMPLETE, StockSnapshotRun::PARTIAL])
            ->latest('source_generated_at')->first();
        $latestConfirmation = $latest === null ? null : StockSnapshotConfirmation::query()
            ->where('stock_snapshot_run_id', $latest->id)->latest('received_at')->first();
        $verifiedAt = $latestConfirmation?->received_at ?? $latest?->completed_at ?? $latest?->source_generated_at;
        $staleMinutes = max(1, (int) config('integrations.pohoda_bridge.stale_minutes', 30));
        $stale = $verifiedAt === null || $verifiedAt->lt(now()->subMinutes($staleMinutes));
        $fullSnapshots24h = StockSnapshotRun::query()->whereIn('status', [StockSnapshotRun::COMPLETE, StockSnapshotRun::PARTIAL])
            ->where('completed_at', '>=', now()->subDay())->count();
        $confirmations24h = StockSnapshotConfirmation::query()->where('received_at', '>=', now()->subDay())->count();
        $runCounts = StockSnapshotRun::query()->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status');
        $counts = BridgeCommand::query()->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status');
        $latestItemCounts = ['mapped' => 0, 'unmapped' => 0, 'rejected' => 0];
        $rejectionCodes = collect();
        if ($latest !== null) {
            $latestItems = StockSnapshotItem::query()
                ->whereHas('batch', fn ($query) => $query->where('stock_snapshot_run_id', $latest->id));
            $itemCounts = (clone $latestItems)->selectRaw(
                'COALESCE(SUM(CASE WHEN accepted = 1 AND product_configuration_id IS NOT NULL THEN 1 ELSE 0 END), 0) mapped,
                 COALESCE(SUM(CASE WHEN accepted = 1 AND product_configuration_id IS NULL THEN 1 ELSE 0 END), 0) unmapped,
                 COALESCE(SUM(CASE WHEN accepted = 0 THEN 1 ELSE 0 END), 0) rejected',
            )->first();
            $latestItemCounts = [
                'mapped' => (int) $itemCounts->mapped,
                'unmapped' => (int) $itemCounts->unmapped,
                'rejected' => (int) $itemCounts->rejected,
            ];
            $rejectionCodes = (clone $latestItems)->where('accepted', false)
                ->whereNotNull('rejection_code')
                ->selectRaw('rejection_code, COUNT(*) total')
                ->groupBy('rejection_code')->orderBy('rejection_code')
                ->pluck('total', 'rejection_code');
        }

        return view('admin.integrations.pohoda', compact(
            'heartbeat', 'complete', 'partial', 'latest', 'stale', 'staleMinutes', 'counts', 'runCounts',
            'latestItemCounts', 'rejectionCodes', 'latestConfirmation', 'verifiedAt', 'fullSnapshots24h', 'confirmations24h',
        ));
    }
}
