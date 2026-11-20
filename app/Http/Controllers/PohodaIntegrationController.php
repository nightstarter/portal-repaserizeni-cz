<?php

namespace App\Http\Controllers;

use App\Models\BridgeCommand;
use App\Models\BridgeHeartbeat;
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
        $staleMinutes = max(1, (int) config('integrations.pohoda_bridge.stale_minutes', 30));
        $stale = $latest === null || $latest->source_generated_at->lt(now()->subMinutes($staleMinutes));
        $runCounts = StockSnapshotRun::query()->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status');
        $counts = BridgeCommand::query()->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status');

        return view('admin.integrations.pohoda', compact(
            'heartbeat', 'complete', 'partial', 'latest', 'stale', 'staleMinutes', 'counts', 'runCounts',
        ));
    }
}
