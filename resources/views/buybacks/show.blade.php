@extends('layouts.app')
@section('content')
@php($thread=$buyback['thread'])
@php($activeInvoice=$buyback['documents']->first(fn($doc) => $doc->status === \App\Enums\DocumentStatus::ACTIVE))
@php($canOperateAk=$buyback['is_ak'] && auth()->user()->role !== \App\Enums\UserRole::READ_ONLY)
<div class="card buyback-hero"><a href="{{ route('buybacks.index') }}">← {{ __('app.buybacks') }}</a><h1>#{{ $order->order_no }}</h1>
<div class="meta"><span><strong>{{ __('app.state') }}</strong><br><span class="badge waiting">{{ __('app.thread_states.'.$buyback['display_state']) }}</span></span><span><strong>{{ __('app.product_code') }}</strong><br>{{ $order->product_code }}</span><span><strong>{{ __('app.technical_type') }}</strong><br>{{ $order->technical_type ? __('app.technical_types.'.$order->technical_type->value) : '—' }}</span><span><strong>{{ __('app.routing') }}</strong><br>{{ $order->routing?->value ?: '—' }}</span></div></div>

<div class="card action-card buyback-now"><h2>{{ __('app.what_now') }}</h2><p>{{ $buyback['next_action'] ? __('app.buyback_actions.'.$buyback['next_action']->value) : __('app.buyback_no_action') }}</p>
@if($buyback['next_action'] === \App\Enums\BuybackAction::SEND_TO_PL)<a class="button" href="{{ route('shipments.create') }}">{{ __('app.open_shipments') }}</a>@endif</div>

<div class="section-nav"><a href="#prices">{{ __('app.buyback_prices') }}</a><a href="#timeline">{{ __('app.history') }}</a><a href="#shipments">{{ __('app.shipments') }}</a><a href="#documents">{{ __('app.documents_finance') }}</a><a href="{{ route('orders.discussion.index', $order) }}">{{ __('app.discussion') }} @if($discussionUnreadCount > 0)<span class="unread-badge">{{ $discussionUnreadCount }}</span>@endif</a></div>

@if($buyback['is_ak'] && $order->customer)<div class="card"><h2>{{ __('app.customer') }}</h2><p>{{ $order->customer->name }}<br>{{ $order->customer->email }}<br>{{ $order->customer->phone }}</p></div>@endif
<div class="card"><h2>{{ __('app.physical_items') }}</h2><p>#{{ $thread->item_id }} · {{ $thread->item->current_location }}</p>@if($buyback['is_ak'] && $thread->item->note)<p class="buyback-note">{{ $thread->item->note }}</p>@endif</div>

<section id="prices" class="card buyback-prices"><h2>{{ __('app.buyback_prices') }}</h2>
@if($buyback['is_ak'])<div class="split"><div><h3>{{ __('app.buyback_internal_offer') }}</h3>@forelse($buyback['internal_quotes'] as $quote)<p>{{ $quote->version }}. {{ \App\Application\Finance\MinorMoney::format($quote->amount_minor) }} {{ $quote->currency }}</p>@empty<p>—</p>@endforelse</div>
<div><h3>{{ __('app.buyback_recommendation') }}</h3><p>{{ $buyback['recommended_minor'] !== null ? \App\Application\Finance\MinorMoney::format($buyback['recommended_minor']).' '.$buyback['recommended_currency'] : '—' }}</p><small>{{ __('app.buyback_recommendation_hint') }}</small></div>
<div><h3>{{ __('app.buyback_final_price') }}</h3>@forelse($buyback['final_quotes'] as $quote)<p>{{ $quote->version }}. {{ \App\Application\Finance\MinorMoney::format($quote->amount_minor) }} {{ $quote->currency }}</p>@empty<p>—</p>@endforelse</div></div>@endif
<h3>{{ __('app.buyback_poland_offer_history') }}</h3>@forelse($buyback['poland_quotes'] as $quote)<p>{{ $quote->version }}. {{ \App\Application\Finance\MinorMoney::format($quote->amount_minor) }} {{ $quote->currency }} · {{ $quote->recorded_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</p>@empty<p>{{ __('app.buyback_no_poland_offer') }}</p>@endforelse
</section>

@foreach($buyback['allowed_actions'] as $action)
@continue(in_array($action, [\App\Enums\BuybackAction::RECORD_INVOICE_TO_PL, \App\Enums\BuybackAction::MARK_PAID_BY_PL], true))
<div class="card buyback-action"><h2>{{ __('app.buyback_actions.'.$action->value) }}</h2>
<form method="post" action="{{ route('orders.buyback.actions', [$order, $thread, $action->value]) }}" data-submit-once>@csrf
<input type="hidden" name="idempotency_key" value="{{ $actionFormKeys[$action->value] }}"><input type="hidden" name="expected_lock_version" value="{{ $thread->lock_version }}">
@if(in_array($action, [\App\Enums\BuybackAction::SET_INTERNAL_PRICE, \App\Enums\BuybackAction::SUBMIT_PL_OFFER, \App\Enums\BuybackAction::APPROVE_FINAL_PRICE], true))<div class="split"><label>{{ __('app.amount') }}<input name="amount" inputmode="decimal" required></label><label>{{ __('app.currency') }}<input name="currency" maxlength="3" value="{{ $action === \App\Enums\BuybackAction::SUBMIT_PL_OFFER ? 'EUR' : 'CZK' }}" required></label></div>@endif
@if(in_array($action, [\App\Enums\BuybackAction::CONFIRM_CUSTOMER_OFFER, \App\Enums\BuybackAction::QUEUE_CUSTOMER_OFFER, \App\Enums\BuybackAction::RETAIN_AK, \App\Enums\BuybackAction::DECIDE_PL_BUY], true))<label class="choice"><input type="checkbox" required><span>{{ __('app.confirm_irreversible') }}</span></label>@endif
<button>{{ __('app.confirm_action') }}</button></form></div>
@endforeach

<section id="timeline" class="card"><h2>{{ __('app.history') }}</h2><ol class="timeline">@foreach($buyback['events'] as $event)<li><h3>{{ __('app.buyback_actions.'.$event->action) }}</h3><p>{{ __('app.thread_states.'.$event->to_state) }}</p><small>{{ $event->performed_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }} · {{ $event->actorOrganization?->name }}</small></li>@endforeach</ol></section>
<section id="shipments" class="card"><h2>{{ __('app.shipments') }}</h2>@forelse($buyback['shipments'] as $shipment)<p><a href="{{ route('shipments.show', $shipment) }}">{{ $shipment->public_number }}</a> · {{ __('app.shipment_statuses.'.$shipment->status->value) }}</p>@empty<p>—</p>@endforelse</section>
<section id="documents" class="card"><h2>{{ __('app.documents_finance') }}</h2>
@forelse($buyback['documents'] as $document)<p>{{ $document->document_number }} · {{ $document->amount }} {{ $document->currency }} · {{ __('app.document_statuses.'.$document->status->value) }} @if($document->status === \App\Enums\DocumentStatus::ACTIVE)<a href="{{ route('documents.download', $document) }}">{{ __('app.download') }}</a>@endif</p>@empty<p>—</p>@endforelse
@if($canOperateAk && $buyback['state'] === \App\Enums\BuybackState::INVOICE_TO_PL_REQUIRED)
<form method="post" enctype="multipart/form-data" action="{{ route('orders.buyback.invoice.store', [$order, $thread]) }}" data-submit-once>@csrf
<input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="expected_lock_version" value="{{ $thread->lock_version }}">
<label>{{ __('app.file') }}<input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required></label><label>{{ __('app.document_number') }}<input name="document_number" required></label><label>{{ __('app.document_date') }}<input type="date" name="document_date" required></label><div class="split"><label>{{ __('app.amount') }}<input name="amount" required></label><label>{{ __('app.currency') }}<input name="currency" value="EUR" required></label></div><button>{{ __('app.buyback_upload_invoice') }}</button></form>@endif
@if($canOperateAk && $buyback['state'] === \App\Enums\BuybackState::WAITING_FOR_PL_PAYMENT && $activeInvoice)
<form method="post" enctype="multipart/form-data" action="{{ route('orders.buyback.invoice.replace', [$order, $thread, $activeInvoice]) }}" data-submit-once>@csrf
<input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="expected_lock_version" value="{{ $thread->lock_version }}">
<label>{{ __('app.file') }}<input type="file" name="file" accept="application/pdf,image/jpeg,image/png" required></label><label>{{ __('app.document_number') }}<input name="document_number" required></label><label>{{ __('app.document_date') }}<input type="date" name="document_date" required></label><div class="split"><label>{{ __('app.amount') }}<input name="amount" required></label><label>{{ __('app.currency') }}<input name="currency" value="{{ $activeInvoice->currency }}" required></label></div><label>{{ __('app.reason') }}<textarea name="reason" required></textarea></label><button>{{ __('app.buyback_replace_invoice') }}</button></form>@endif
@if($buyback['is_ak'] && $buyback['state'] === \App\Enums\BuybackState::WAITING_FOR_PL_PAYMENT && auth()->user()->hasPermission(\App\Enums\Permission::BUYBACK_PAYMENT_CONFIRM) && $activeInvoice)
<form method="post" action="{{ route('orders.buyback.actions', [$order, $thread, \App\Enums\BuybackAction::MARK_PAID_BY_PL->value]) }}" data-submit-once>@csrf<input type="hidden" name="idempotency_key" value="{{ $actionFormKeys[\App\Enums\BuybackAction::MARK_PAID_BY_PL->value] }}"><input type="hidden" name="expected_lock_version" value="{{ $thread->lock_version }}"><input type="hidden" name="document_id" value="{{ $activeInvoice->id }}"><button>{{ __('app.buyback_mark_paid') }}</button></form>@endif
@if($buyback['is_ak'] && $buyback['finance_entries']->isNotEmpty())
<h3>{{ __('app.finance_history') }}</h3>
@foreach($buyback['finance_entries'] as $entry)<p>{{ $entry->amount }} {{ $entry->currency }} · {{ $entry->recorded_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</p>@endforeach
@endif
</section>
@endsection
