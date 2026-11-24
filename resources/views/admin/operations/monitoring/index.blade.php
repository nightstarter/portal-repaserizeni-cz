@extends('layouts.app')
@section('content')
<h1>{{ __('app.monitoring.title') }}</h1>
<p class="muted">{{ __('app.monitoring.read_only') }}</p>

<section class="card">
    <h2>{{ __('app.monitoring.last_run') }}</h2>
    @if($latestRun)
        <div class="meta">
            <span><strong>{{ __('app.monitoring.status') }}</strong><br>{{ $latestRun->status }}</span>
            <span><strong>{{ __('app.monitoring.started') }}</strong><br>{{ $latestRun->started_at?->format('d.m.Y H:i:s') }}</span>
            <span><strong>{{ __('app.monitoring.completed') }}</strong><br>{{ $latestRun->completed_at?->format('d.m.Y H:i:s') ?? '—' }}</span>
        </div>
    @else
        <div class="empty-state"><strong>{{ __('app.monitoring.no_runs') }}</strong></div>
    @endif
</section>

<section class="card">
    <h2>{{ __('app.monitoring.alerts') }}</h2>
    <form method="get" action="{{ route('admin.operations.monitoring.index') }}">
        <div class="split">
            <label>{{ __('app.monitoring.severity') }}<select name="severity"><option value="">—</option>@foreach(['INFO','WARN','ERROR','CRITICAL'] as $value)<option @selected(($filters['severity'] ?? '') === $value)>{{ $value }}</option>@endforeach</select></label>
            <label>{{ __('app.monitoring.lifecycle') }}<select name="lifecycle"><option value="">—</option>@foreach(['OPEN','ACKNOWLEDGED','RESOLVED'] as $value)<option @selected(($filters['lifecycle'] ?? '') === $value)>{{ $value }}</option>@endforeach</select></label>
            <label>{{ __('app.monitoring.check_key') }}<input name="check_key" value="{{ $filters['check_key'] ?? '' }}" maxlength="80"></label>
        </div>
        <button>{{ __('app.monitoring.filter') }}</button>
    </form>
    @if($alerts->isEmpty())
        <div class="empty-state"><strong>{{ __('app.monitoring.no_alerts') }}</strong><p>{{ __('app.monitoring.no_alerts_hint') }}</p></div>
    @else
        <div class="table-scroll"><table><thead><tr><th>{{ __('app.monitoring.severity') }}</th><th>{{ __('app.monitoring.lifecycle') }}</th><th>{{ __('app.monitoring.check_key') }}</th><th>{{ __('app.monitoring.summary') }}</th><th>{{ __('app.monitoring.occurrences') }}</th></tr></thead><tbody>
        @foreach($alerts as $alert)<tr><td>{{ $alert->severity }}</td><td>{{ $alert->lifecycle }}</td><td><a href="{{ route('admin.operations.monitoring.show', $alert) }}"><code>{{ $alert->check_key }}</code></a></td><td>{{ $alert->summary }}</td><td>{{ $alert->occurrence_count }}</td></tr>@endforeach
        </tbody></table></div>
        {{ $alerts->links() }}
    @endif
</section>
@endsection
