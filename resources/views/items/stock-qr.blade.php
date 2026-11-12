@extends('layouts.app')
@section('content')
<section class="card mobile-hero">
    <p><strong>#{{ $order->order_no }}</strong> · {{ $order->product_code }}</p>
    <h1>{{ __('app.item_roles.'.$selectedItem->role->value) }}</h1>
    <p>{{ __('app.locations.'.$selectedItem->current_location) }} · {{ __('app.thread_types.'.$workflow['thread']->type->value) }}</p>
    <span class="badge">{{ __('app.thread_states.'.$workflow['thread']->state) }}</span>
</section>

<section class="card mobile-primary">
    <h2>{{ __('app.what_now') }}</h2>
    @include('orders.partials.stock-action-panel')
</section>

<section class="card">
    <h2>{{ __('app.physical_item') }}</h2>
    <p><strong>{{ __('app.item_role') }}:</strong> {{ __('app.item_roles.'.$selectedItem->role->value) }}</p>
    <p><strong>{{ __('app.location') }}:</strong> {{ __('app.locations.'.$selectedItem->current_location) }}</p>
    <p><strong>{{ __('app.related_branch') }}:</strong> {{ __('app.thread_types.'.$workflow['thread']->type->value) }}</p>
    <a href="{{ route('orders.show', $order) }}">{{ __('app.full_order_detail') }} →</a>
</section>

@if($workflow['thread']->type === \App\Enums\ProcessThreadType::CORE_RETURN)
<section class="card notice"><strong>{{ __('app.core_return_required') }}</strong><p>{{ __('app.core_return_later') }}</p></section>
@endif

@include('orders.partials.stock-timeline')
@endsection
