@extends('layouts.app')
@section('content')
<section class="card mobile-hero">
    <p><strong>#{{ $order->order_no }}</strong> · {{ $order->product_code }}</p>
    <h1>{{ $order->vehicle ?: __('app.product') }}</h1>
    <p>{{ $order->technical_type ? __('app.technical_types.'.$order->technical_type->value) : '—' }} · {{ $order->routing?->value ?: '—' }}</p>
    <span class="badge">{{ __('app.thread_states.'.$workflow['thread']->state) }}</span>
</section>

@include('orders.partials.action-panel')

<section class="card">
    <h2>{{ __('app.physical_items') }}</h2>
    <p><strong>{{ __('app.item_role') }}:</strong> {{ __('app.item_roles.'.$selectedItem->role->value) }}</p>
    <p><strong>{{ __('app.location') }}:</strong> {{ __('app.locations.'.$selectedItem->current_location) }}</p>
    <a href="{{ route('orders.show', $order) }}">{{ __('app.full_order_detail') }} →</a>
</section>

@include('orders.partials.receipt-data', ['item' => $selectedItem])
@include('orders.partials.timeline')
@endsection
