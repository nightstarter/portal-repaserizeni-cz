<?php

namespace App\Http\Controllers;

use App\Models\BridgeCommand;
use App\Models\BridgeHeartbeat;
use App\Models\StockSnapshotBatch;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PohodaIntegrationController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless($request->user()->isAdministrator()
            && $request->user()->organization->code->value === 'AUTOKLUBY', 404);
        $heartbeat = BridgeHeartbeat::query()->latest('occurred_at')->first();
        $complete = StockSnapshotBatch::query()->where('status', 'COMPLETE')->latest('source_generated_at')->first();
        $partial = StockSnapshotBatch::query()->where('status', 'PARTIAL')->latest('source_generated_at')->first();
        $latest = StockSnapshotBatch::query()->latest('source_generated_at')->first();
        $staleMinutes = max(1, (int) config('integrations.pohoda_bridge.stale_minutes', 30));
        $stale = $latest === null || $latest->source_generated_at->lt(now()->subMinutes($staleMinutes));
        $counts = BridgeCommand::query()->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status');

        return view('admin.integrations.pohoda', compact(
            'heartbeat', 'complete', 'partial', 'latest', 'stale', 'staleMinutes', 'counts',
        ));
    }
}
