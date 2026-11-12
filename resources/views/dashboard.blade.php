@extends('layouts.app')
@section('content')
<h1>{{ __('app.dashboard') }}</h1>
<p class="muted">{{ __('app.phase_2d_ready') }}</p>
<div class="kpis">
    <div class="card kpi"><span>{{ __('app.orders_total') }}</span><strong>{{ $totalOrders }}</strong></div>
    <div class="card kpi"><span>{{ __('app.waiting_for_us') }}</span><strong>{{ $waitingForUs }}</strong></div>
    <div class="card kpi"><span>{{ __('app.waiting_for_poland') }}</span><strong>{{ $waitingForPoland }}</strong></div>
    @unless($isPoland)<div class="card kpi"><span>{{ __('app.integration_errors') }}</span><strong>{{ $integrationErrors }}</strong></div>@endunless
</div>

<div class="queue-nav" role="navigation" aria-label="{{ __('app.stock_queues') }}">
    <a class="queue-link {{ $activeQueue === null ? 'active' : '' }}" href="{{ route('dashboard') }}">{{ __('app.all_branches') }}</a>
    @foreach($availableQueues as $queue)
        <a class="queue-link {{ $activeQueue === $queue ? 'active' : '' }}" href="{{ route('dashboard', ['queue' => $queue]) }}">
            {{ __('app.queues.'.$queue) }} <strong>{{ $queueCounts[$queue] }}</strong>
        </a>
    @endforeach
</div>

<div class="card">
    <h2>{{ __('app.process_branches') }}</h2>
    <div class="table-scroll"><table class="responsive">
        <thead><tr>
            <th>{{ __('app.order_no') }} / {{ __('app.product') }}</th>
            <th>{{ __('app.vehicle') }} / {{ __('app.vin') }}</th>
            <th>{{ __('app.technical_type') }} / {{ __('app.routing') }}</th>
            <th>{{ __('app.branch') }} / {{ __('app.state') }}</th>
            <th>{{ __('app.next_responsibility') }}</th>
            <th>{{ __('app.eta') }}</th>
            <th>{{ __('app.next_action') }}</th>
            <th>{{ __('app.last_event') }}</th>
        </tr></thead>
        <tbody>
        @forelse($rows as $row)
            @php($order = $row['order'])
            @php($workflow = $row['workflow'])
            @php($thread = $workflow['thread'])
            <tr>
                <td data-label="{{ __('app.order_no') }} / {{ __('app.product') }}"><a href="{{ route('orders.show', $order) }}"><strong>#{{ $order->order_no }}</strong></a><br><span class="muted">{{ $order->product_code }}</span></td>
                <td data-label="{{ __('app.vehicle') }} / {{ __('app.vin') }}">{{ $order->vehicle ?: '—' }}<br><span class="muted">{{ $order->vin ?: '—' }}</span></td>
                <td data-label="{{ __('app.technical_type') }} / {{ __('app.routing') }}">{{ $order->technical_type ? __('app.technical_types.'.$order->technical_type->value) : '—' }}<br><span class="badge">{{ $order->routing?->value ?: '—' }}</span></td>
                <td data-label="{{ __('app.branch') }} / {{ __('app.state') }}"><strong>{{ __('app.thread_types.'.$thread->type->value) }}</strong><br><span class="badge {{ $thread->completed_at ? 'done' : 'waiting' }}">{{ __('app.thread_states.'.$thread->state) }}</span></td>
                <td data-label="{{ __('app.next_responsibility') }}">{{ $thread->responsibleOrganization?->name ?? __('app.responsible_parties.'.$thread->responsible_party->value) }}</td>
                <td data-label="{{ __('app.eta') }}">{{ $thread->eta?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '—' }}</td>
                <td data-label="{{ __('app.next_action') }}">
                    @if($workflow['next_action'])
                        <a class="button compact" href="{{ route('orders.show', $order) }}#thread-{{ $thread->id }}">{{ __('app.actions.'.$workflow['next_action']->value) }}</a>
                    @else — @endif
                </td>
                <td data-label="{{ __('app.last_event') }}">{{ $row['kind'] === 'custom' ? ($workflow['last_event_at']?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '—') : ($workflow['last_event']?->performed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '—') }}</td>
            </tr>
        @empty
            <tr><td colspan="8">{{ __('app.no_orders') }}</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
<p class="muted">{{ __('app.import_disabled') }}</p>
@endsection
