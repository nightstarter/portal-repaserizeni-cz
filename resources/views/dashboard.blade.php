@extends('layouts.app')
@section('content')
<h1>{{ __('app.dashboard') }}</h1>
<p class="muted">{{ __('app.phase_1a_ready') }}</p>
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
        <thead><tr><th>{{ __('app.order_no') }}</th><th>{{ __('app.vehicle') }}</th><th>{{ __('app.order_type') }}</th><th>{{ __('app.active_threads') }}</th><th>{{ __('app.next_responsibility') }}</th></tr></thead>
        <tbody>
        @forelse($orders as $order)
            <tr>
                <td><a href="{{ route('orders.show', $order) }}"><strong>#{{ $order->order_no }}</strong></a><br><span class="muted">{{ $order->product_code }}</span></td>
                <td>{{ $order->vehicle ?: '—' }}</td>
                <td>{{ __('app.order_types.'.$order->order_type->value) }}</td>
                <td>@foreach($order->processThreads as $thread)<span class="badge">{{ __('app.thread_types.'.$thread->type->value) }}: {{ __('app.thread_states.'.$thread->state) }}</span>@endforeach</td>
                <td>{{ $order->processThreads->whereNull('completed_at')->map(fn($thread) => __('app.responsible_parties.'.$thread->responsible_party->value))->unique()->join(', ') ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="5">{{ __('app.no_orders') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<p class="muted">{{ __('app.import_disabled') }}</p>
@endsection
