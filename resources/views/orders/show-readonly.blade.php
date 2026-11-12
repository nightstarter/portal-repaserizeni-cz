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
    </div>
</div>
<h2>{{ __('app.process_threads') }}</h2>
<div class="cards">
@foreach($order->processThreads as $thread)
    <div class="card"><h3>{{ __('app.thread_types.'.$thread->type->value) }}</h3><span class="badge waiting">{{ __('app.thread_states.'.$thread->state) }}</span><p>{{ __('app.next_responsibility') }}: {{ __('app.responsible_parties.'.$thread->responsible_party->value) }}</p></div>
@endforeach
</div>
<div class="card"><h2>{{ __('app.physical_items') }}</h2><table><tbody>@foreach($order->items as $item)<tr><td>{{ __('app.item_roles.'.$item->role->value) }}</td><td>{{ $item->current_location }}</td></tr>@endforeach</tbody></table></div>
@if(auth()->user()->organization->code === \App\Enums\OrganizationCode::AUTOKLUBY && $order->customer)<div class="card"><h2>{{ __('app.customer') }}</h2><p>{{ $order->customer->name }}<br>{{ $order->customer->email }}<br>{{ $order->customer->phone }}</p></div>@endif
<p class="muted">{{ __('app.read_only_phase_1a') }}</p>
@endsection
