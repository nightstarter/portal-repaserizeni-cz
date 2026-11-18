@extends('layouts.app')
@section('content')
<div class="card">
    <a href="{{ route('orders.show', $order) }}">← {{ __('app.back_to_order') }}</a>
    <h1>{{ __('app.discussion') }} · #{{ $order->order_no }}</h1>
    <p class="muted">{{ $order->product_code }}</p>
    <div class="section-nav" role="navigation" aria-label="{{ __('app.order_sections') }}">
        <a href="{{ route('orders.show', $order) }}">{{ __('app.section_overview') }}</a>
        <a href="{{ route('orders.discussion.index', $order) }}" aria-current="page">{{ __('app.discussion') }}</a>
    </div>
</div>

@if($unreadCount > 0)
<section class="card">
    <p><strong>{{ trans_choice('app.unread_messages', $unreadCount, ['count' => $unreadCount]) }}</strong></p>
    <form method="post" action="{{ route('orders.discussion.read', $order) }}">@csrf<button>{{ __('app.mark_all_read') }}</button></form>
</section>
@endif

<section class="discussion-list" aria-label="{{ __('app.discussion') }}">
@forelse($messages as $message)
    <article class="card message-card">
        <header class="message-header">
            <strong>{{ $message->author->name }} · {{ $message->authorOrganization->name }}</strong>
            <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</time>
        </header>
        <p class="message-body">{!! nl2br(e($message->body)) !!}</p>
        @if($message->attachments->isNotEmpty())
            <ul class="attachment-list">
            @foreach($message->attachments as $attachment)
                <li><a href="{{ route('message-attachments.download', $attachment) }}">{{ $attachment->original_name }}</a> <span class="muted">({{ number_format($attachment->size / 1024, 1, ',', ' ') }} kB)</span></li>
            @endforeach
            </ul>
        @endif
    </article>
@empty
    <div class="card empty-state"><strong>{{ __('app.no_messages') }}</strong><p class="muted">{{ __('app.no_messages_hint') }}</p></div>
@endforelse
</section>

@can('create', [\App\Models\Message::class, $order])
<section class="card">
    <h2>{{ __('app.new_message') }}</h2>
    <form method="post" enctype="multipart/form-data" action="{{ route('orders.discussion.store', $order) }}">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ $messageFormKey }}">
        <label>{{ __('app.message_text') }}<textarea name="body" maxlength="10000" required>{{ old('body') }}</textarea></label>
        <label>{{ __('app.message_attachments') }}<input type="file" name="attachments[]" multiple accept="application/pdf,image/jpeg,image/png"></label>
        <p class="muted">{{ __('app.message_attachment_hint') }}</p>
        <button>{{ __('app.send_message') }}</button>
    </form>
</section>
@endcan
@endsection
