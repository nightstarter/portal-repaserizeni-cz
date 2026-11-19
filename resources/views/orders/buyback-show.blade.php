@extends('layouts.app')
@section('content')
<div class="card">
    <a href="{{ route('dashboard') }}">← {{ __('app.dashboard') }}</a>
    <h1>#{{ $order->order_no }}</h1>
    <div class="meta">
        <span><strong>{{ __('app.product_code') }}</strong><br>{{ $order->product_code }}</span>
        <span><strong>{{ __('app.technical_type') }}</strong><br>{{ $order->technical_type?->value ?: '—' }}</span>
        <span><strong>{{ __('app.routing') }}</strong><br>{{ $order->routing?->value ?: '—' }}</span>
        <span><strong>{{ __('app.current_state') }}</strong><br>{{ __('app.thread_states.'.$state) }}</span>
    </div>
</div>
<div class="card"><h2>{{ __('app.physical_items') }}</h2><p>#{{ $buyback->item_id }} · {{ $buyback->item->current_location }}</p></div>
<div class="card">
    <h2>{{ __('app.buyback_poland_offer_history') }}</h2>
    @forelse($buyback->buybackPriceQuotes as $quote)
        <p>{{ $quote->version }}. {{ intdiv($quote->amount_minor, 100) }},{{ str_pad((string) ($quote->amount_minor % 100), 2, '0', STR_PAD_LEFT) }} {{ $quote->currency }}</p>
    @empty
        <p class="muted">{{ __('app.buyback_no_poland_offer') }}</p>
    @endforelse
</div>
@if($buyback->documents->isNotEmpty())
<div class="card"><h2>{{ __('app.documents_finance') }}</h2>
    @foreach($buyback->documents as $document)<p><a href="{{ route('documents.download', $document) }}">{{ __('app.download') }} – {{ $document->document_number }}</a></p>@endforeach
</div>
@endif
@endsection
