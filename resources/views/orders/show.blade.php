@extends('layouts.app')
@section('content')
<div class="card">
    <a href="{{ route('dashboard') }}">← {{ __('app.dashboard') }}</a>
    <h1>#{{ $order->order_no }}</h1>
    <div class="meta">
        <span><strong>{{ __('app.order_type') }}</strong><br>{{ __('app.order_types.'.$order->order_type->value) }}</span>
        <span><strong>{{ __('app.handover') }}</strong><br>{{ $order->customer_handover ? __('app.handover_types.'.$order->customer_handover) : '—' }}</span>
        <span><strong>{{ __('app.last_event') }}</strong><br>{{ $workflow['last_event_at']?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '—' }}</span>
    </div>
</div>

@include('orders.partials.action-panel')

<div class="split">
    <section class="card">
        <h2>{{ __('app.product_vehicle') }}</h2>
        <p><strong>{{ __('app.product_code') }}:</strong> {{ $order->product_code }}</p>
        <p><strong>{{ __('app.vehicle') }}:</strong> {{ $order->vehicle ?: '—' }}</p>
        <p><strong>{{ __('app.vin') }}:</strong> {{ $order->vin ?: '—' }}</p>
        <p><strong>{{ __('app.technical_type') }}:</strong> {{ $order->technical_type ? __('app.technical_types.'.$order->technical_type->value) : '—' }}</p>
        <p><strong>{{ __('app.routing') }}:</strong> {{ $order->routing?->value ?: '—' }}</p>
    </section>
    <section class="card">
        <h2>{{ __('app.branch') }}</h2>
        <p><span class="badge {{ $workflow['thread']->completed_at ? 'done' : 'waiting' }}">{{ __('app.thread_states.'.$workflow['thread']->state) }}</span></p>
        <p><strong>{{ __('app.next_responsibility') }}:</strong> {{ __('app.responsible_parties.'.$workflow['thread']->responsible_party->value) }}</p>
        <p><strong>{{ __('app.next_action') }}:</strong> {{ $workflow['next_action'] ? __('app.actions.'.$workflow['next_action']->value) : '—' }}</p>
    </section>
</div>

<section class="card">
    <h2>{{ __('app.physical_items') }}</h2>
    <div class="table-scroll"><table><thead><tr><th>{{ __('app.item_role') }}</th><th>{{ __('app.location') }}</th><th>QR</th></tr></thead><tbody>
    @foreach($order->items as $item)
        <tr>
            <td>{{ __('app.item_roles.'.$item->role->value) }}</td>
            <td>{{ __('app.locations.'.$item->current_location) }}</td>
            <td><a class="button" href="{{ route('items.qr', $item->qr_token) }}">{{ __('app.open_qr_detail') }}</a></td>
        </tr>
    @endforeach
    </tbody></table></div>
</section>

@include('orders.partials.receipt-data', ['item' => $order->items->first()])

@if(auth()->user()->organization->code === \App\Enums\OrganizationCode::AUTOKLUBY && $order->customer)
<section class="card">
    <h2>{{ __('app.customer') }}</h2>
    <p>{{ $order->customer->name }}<br>{{ $order->customer->email }}<br>{{ $order->customer->phone }}</p>
    <p>{{ $order->customer->street }}<br>{{ $order->customer->postal_code }} {{ $order->customer->city }}</p>
</section>
@endif

@include('orders.partials.timeline')
@endsection
