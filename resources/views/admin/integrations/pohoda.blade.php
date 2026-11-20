@extends('layouts.app')
@section('content')
<h1>{{ __('app.pohoda_bridge_title') }}</h1>
<div class="notice"><strong>{{ config('integrations.pohoda_bridge.enabled') ? __('app.enabled') : __('app.disabled') }}</strong></div>
@if($stale)<div class="notice error"><strong>STALE</strong> · {{ __('app.pohoda_bridge_stale_hint', ['minutes' => $staleMinutes]) }}</div>@endif
<div class="kpis">
<div class="card kpi"><span>{{ __('app.pohoda_last_heartbeat') }}</span><strong>{{ $heartbeat?->occurred_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '—' }}</strong><small>{{ $heartbeat?->connector_version ?? '—' }} · {{ $heartbeat?->status ?? '—' }}</small></div>
<div class="card kpi"><span>{{ __('app.pohoda_last_complete_snapshot') }}</span><strong>{{ $complete?->source_generated_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '—' }}</strong></div>
<div class="card kpi"><span>{{ __('app.pohoda_last_partial_snapshot') }}</span><strong>{{ $partial?->source_generated_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '—' }}</strong></div>
</div>
<section class="card"><h2>{{ __('app.pohoda_latest_batch') }}</h2>
@if($latest)
<p>{{ $latest->run_id }} · {{ $latest->warehouse_code }} · {{ $latest->received_chunks }}/{{ $latest->chunk_count }}</p>
<p>{{ __('app.pohoda_items_accepted') }}: {{ $latest->accepted_count }} · {{ __('app.pohoda_items_mapped') }}: {{ $latestItemCounts['mapped'] }} · {{ __('app.pohoda_items_unmapped') }}: {{ $latestItemCounts['unmapped'] }} · {{ __('app.pohoda_items_rejected') }}: {{ $latestItemCounts['rejected'] }}</p>
@if($rejectionCodes->isNotEmpty())<p>{{ __('app.pohoda_rejection_codes') }}: {{ $rejectionCodes->map(fn($total, $code) => $code.': '.$total)->implode(' · ') }}</p>@endif
@else<p>{{ __('app.pohoda_no_snapshot') }}</p>@endif
<p>receiving: {{ $runCounts[\App\Models\StockSnapshotRun::RECEIVING] ?? 0 }} · failed: {{ $runCounts[\App\Models\StockSnapshotRun::FAILED] ?? 0 }}</p>
</section>
<section class="card"><h2>{{ __('app.pohoda_commands') }}</h2>
<p>pending: {{ $counts[\App\Models\BridgeCommand::PENDING] ?? 0 }} · processing: {{ $counts[\App\Models\BridgeCommand::PROCESSING] ?? 0 }} · failed: {{ $counts[\App\Models\BridgeCommand::FAILED] ?? 0 }}</p>
@if(($counts[\App\Models\BridgeCommand::FAILED] ?? 0) > 0)<p>{{ __('app.pohoda_sanitized_errors_only') }}</p>@endif
</section>
@endsection
