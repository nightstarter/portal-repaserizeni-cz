@extends('layouts.app')
@section('content')
<section class="card mobile-hero">
    <p><strong>#{{ $order->order_no }}</strong> · {{ __('app.item_identifier', ['id' => $selectedItem->id]) }}</p>
    <h1>{{ __('app.warranty_number', ['number' => $warranty['thread']->warranty_cycle]) }}</h1>
    <span class="badge">{{ __('app.thread_states.'.$warranty['thread']->state) }}</span>
</section>
<section class="card mobile-primary"><h2>{{ __('app.what_now') }}</h2>@include('orders.partials.warranty-action-panel')</section>
<section class="card"><h2>{{ __('app.physical_item') }}</h2><p>{{ __('app.item_roles.'.$selectedItem->role->value) }} · {{ __('app.locations.'.$selectedItem->current_location) }}</p><a href="{{ route('orders.show', $order) }}#warranty-thread-{{ $warranty['thread']->id }}">{{ __('app.full_order_detail') }} →</a></section>
<section class="card"><h2>{{ __('app.history') }}</h2><ol class="timeline">@foreach($warranty['timeline'] as $event)<li><h3>{{ __('app.warranty_actions.'.$event['action']) }}</h3><p>{{ __('app.thread_states.'.$event['to_state']) }}</p><p class="muted">{{ $event['performed_at']->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</p>@if($event['reason'])<p>{{ $event['reason'] }}</p>@endif</li>@endforeach</ol></section>
@endsection
