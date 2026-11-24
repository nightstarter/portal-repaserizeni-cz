@extends('layouts.app')
@section('content')
<p><a href="{{ route('admin.operations.monitoring.index') }}">← {{ __('app.monitoring.back') }}</a></p>
<h1>{{ __('app.monitoring.alert_detail') }}</h1>
<section class="card">
    <div class="meta"><span><strong>{{ __('app.monitoring.check_key') }}</strong><br><code>{{ $alert->check_key }}</code></span><span><strong>{{ __('app.monitoring.severity') }}</strong><br>{{ $alert->severity }}</span><span><strong>{{ __('app.monitoring.lifecycle') }}</strong><br>{{ $alert->lifecycle }}</span><span><strong>{{ __('app.monitoring.occurrences') }}</strong><br>{{ $alert->occurrence_count }}</span></div>
    <p>{{ $alert->summary }}</p>
    @if($alert->lifecycle !== 'RESOLVED')
        <form method="post" action="{{ route('admin.operations.monitoring.acknowledge', $alert) }}">@csrf
            <input type="hidden" name="row_version" value="{{ $alert->row_version }}">
            <label>{{ __('app.monitoring.ack_note') }}<textarea name="note" maxlength="500" required></textarea></label>
            <p class="muted">{{ __('app.monitoring.no_pii') }}</p>
            <button>{{ __('app.monitoring.ack') }}</button>
        </form>
    @endif
</section>
<section class="card"><h2>{{ __('app.monitoring.history') }}</h2>
    @if($alert->events->isEmpty())<div class="empty-state">{{ __('app.monitoring.no_history') }}</div>@else
    <div class="table-scroll"><table><thead><tr><th>{{ __('app.monitoring.date') }}</th><th>{{ __('app.monitoring.event') }}</th><th>{{ __('app.monitoring.severity') }}</th><th>{{ __('app.monitoring.note') }}</th></tr></thead><tbody>
    @foreach($alert->events as $event)<tr><td>{{ $event->created_at?->format('d.m.Y H:i:s') }}</td><td>{{ $event->event_type }}</td><td>{{ $event->severity }}</td><td>{{ $event->note ?? '—' }}</td></tr>@endforeach
    </tbody></table></div>@endif
</section>
@endsection
