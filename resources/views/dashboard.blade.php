@extends('layouts.app')
@section('content')
<h1>{{ __('app.dashboard') }}</h1>
<p class="muted">{{ __('app.phase_2b_ready') }}</p>
<div class="kpis">
    <div class="card kpi"><span>{{ __('app.orders_total') }}</span><strong>{{ $totalOrders }}</strong></div>
    <div class="card kpi"><span>{{ __('app.waiting_for_us') }}</span><strong>{{ $waitingForUs }}</strong></div>
    <div class="card kpi"><span>{{ __('app.waiting_for_poland') }}</span><strong>{{ $waitingForPoland }}</strong></div>
    @if(auth()->user()->organization->code === \App\Enums\OrganizationCode::AUTOKLUBY)
        <div class="card kpi"><span>{{ __('app.integration_errors') }}</span><strong>{{ $integrationErrors }}</strong></div>
    @endif
</div>
<div class="card">
    <h2>{{ __('app.orders') }}</h2>
    <table class="responsive">
        <thead><tr><th>{{ __('app.order_no') }} / {{ __('app.product') }}</th><th>{{ __('app.vehicle') }} / {{ __('app.vin') }}</th><th>{{ __('app.technical_type') }} / {{ __('app.routing') }}</th><th>{{ __('app.state') }}</th><th>{{ __('app.next_responsibility') }}</th><th>{{ __('app.next_action') }}</th><th>{{ __('app.last_event') }}</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            @php($order = $row['order'])
            @php($workflow = $row['workflow'])
            @php($thread = $workflow ? $workflow['thread'] : $order->processThreads->first())
            <tr>
                <td data-label="{{ __('app.order_no') }} / {{ __('app.product') }}"><a href="{{ route('orders.show', $order) }}"><strong>#{{ $order->order_no }}</strong></a><br><span class="muted">{{ $order->product_code }}</span></td>
                <td data-label="{{ __('app.vehicle') }} / {{ __('app.vin') }}">{{ $order->vehicle ?: '—' }}<br><span class="muted">{{ $order->vin ?: '—' }}</span></td>
                <td data-label="{{ __('app.technical_type') }} / {{ __('app.routing') }}">{{ $order->technical_type ? __('app.technical_types.'.$order->technical_type->value) : '—' }}<br><span class="badge">{{ $order->routing?->value ?: '—' }}</span></td>
                <td data-label="{{ __('app.state') }}">
                    @if($workflow)<span class="badge {{ $thread->completed_at ? 'done' : 'waiting' }}">{{ __('app.thread_states.'.$thread->state) }}</span>
                    @else @foreach($order->processThreads as $legacyThread)<span class="badge">{{ __('app.thread_types.'.$legacyThread->type->value) }}: {{ __('app.thread_states.'.$legacyThread->state) }}</span>@endforeach
                    @endif
                </td>
                <td data-label="{{ __('app.next_responsibility') }}">{{ $workflow ? __('app.responsible_parties.'.$thread->responsible_party->value) : $order->processThreads->map(fn($legacyThread) => __('app.responsible_parties.'.$legacyThread->responsible_party->value))->unique()->join(', ') }}</td>
                <td data-label="{{ __('app.next_action') }}">{{ $workflow && $workflow['next_action'] ? __('app.actions.'.$workflow['next_action']->value) : '—' }}</td>
                <td data-label="{{ __('app.last_event') }}">{{ $workflow ? ($workflow['last_event_at']?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '—') : ($thread?->events->sortByDesc('id')->first()?->performed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '—') }}</td>
            </tr>
        @empty
            <tr><td colspan="7">{{ __('app.no_orders') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<p class="muted">{{ __('app.import_disabled') }}</p>
@endsection
