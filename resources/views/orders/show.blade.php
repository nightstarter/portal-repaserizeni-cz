@extends('layouts.app')
@section('content')
<div class="card">
    <a href="{{ route('dashboard') }}">← {{ __('app.dashboard') }}</a>
    <h1>#{{ $order->order_no }}</h1>
    <div class="meta">
        <span><strong>{{ __('app.vehicle') }}</strong><br>{{ $order->vehicle ?: '—' }}</span>
        <span><strong>{{ __('app.product_code') }}</strong><br>{{ $order->product_code }}</span>
        <span><strong>{{ __('app.technical_type') }}</strong><br>{{ $order->technical_type?->value ?: '—' }}</span>
        <span><strong>{{ __('app.routing') }}</strong><br>{{ $order->routing?->value ?: '—' }}</span>
        <span><strong>{{ __('app.order_type') }}</strong><br>{{ __('app.order_types.'.$order->order_type->value) }}</span>
    </div>
</div>

<h2>{{ __('app.process_threads') }}</h2>
<div class="cards">
    @foreach($order->processThreads as $thread)
        <div class="card">
            <h3>{{ __('app.thread_types.'.$thread->type->value) }}</h3>
            <p><span class="badge waiting">{{ __('app.thread_states.'.$thread->state) }}</span></p>
            <p><strong>{{ __('app.next_responsibility') }}:</strong> {{ __('app.responsible_parties.'.$thread->responsible_party->value) }}</p>
            @if($thread->eta)<p><strong>ETA:</strong> {{ $thread->eta->format('d.m.Y') }}</p>@endif
        </div>
    @endforeach
</div>

<div class="card">
    <h2>{{ __('app.physical_items') }}</h2>
    <table><thead><tr><th>{{ __('app.item_role') }}</th><th>{{ __('app.location') }}</th><th>QR</th></tr></thead><tbody>
    @forelse($order->items as $item)
        <tr><td>{{ __('app.item_roles.'.$item->role->value) }}</td><td>{{ $item->current_location }}</td><td><code>{{ substr($item->qr_token, 0, 8) }}…</code></td></tr>
    @empty
        <tr><td colspan="3">{{ __('app.no_items') }}</td></tr>
    @endforelse
    </tbody></table>
</div>

@if(auth()->user()->organization->code === \App\Enums\OrganizationCode::AUTOKLUBY && $order->customer)
<div class="card">
    <h2>{{ __('app.customer') }}</h2>
    <p>{{ $order->customer->name }}<br>{{ $order->customer->email }}<br>{{ $order->customer->phone }}</p>
</div>
@endif
<p class="muted">{{ __('app.read_only_phase_1a') }}</p>
@endsection
