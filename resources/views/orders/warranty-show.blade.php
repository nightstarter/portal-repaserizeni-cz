@extends('layouts.app')
@section('content')
<div class="card" id="overview">
    <a href="{{ route('warranties.index') }}">← {{ __('app.warranties') }}</a>
    <h1>#{{ $order->order_no }}</h1>
    <div class="meta">
        <span><strong>{{ __('app.product_code') }}</strong><br>{{ $order->product_code }}</span>
        <span><strong>{{ __('app.technical_type') }}</strong><br>{{ $order->technical_type ? __('app.technical_types.'.$order->technical_type->value) : '—' }}</span>
        <span><strong>{{ __('app.routing') }}</strong><br>{{ $order->routing?->value ?: '—' }}</span>
    </div>
</div>
<div class="section-nav" role="navigation" aria-label="{{ __('app.order_sections') }}">
    <a href="#overview">{{ __('app.section_overview') }}</a>
    <a href="#warranties">{{ __('app.warranties') }}</a>
    <a href="{{ route('orders.discussion.index', $order) }}">{{ __('app.discussion') }} @if($discussionUnreadCount > 0)<span class="unread-badge">{{ $discussionUnreadCount }}</span>@endif</a>
</div>
@include('orders.partials.warranties')
@endsection
