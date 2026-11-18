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

<nav class="pagination" aria-label="{{ __('app.pagination') }}">
    @if($messages->nextPageUrl())<a class="button compact" rel="next" href="{{ $messages->nextPageUrl() }}">{{ __('app.load_older') }}</a>@endif
    @if($messages->previousPageUrl())<a class="button compact" rel="prev" href="{{ $messages->previousPageUrl() }}">{{ __('app.load_newer') }}</a>@endif
</nav>

<section class="discussion-list" aria-label="{{ __('app.discussion') }}">
@forelse($messages->getCollection()->reverse() as $message)
    <article class="card message-card organization-{{ strtolower($message->authorOrganization->code->value) }}">
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
    <h2>{{ __('app.write_message') }}</h2>
    <form class="discussion-form" data-submit-once method="post" enctype="multipart/form-data" action="{{ route('orders.discussion.store', $order) }}">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ $messageFormKey }}">
        <label>{{ __('app.message_text') }}<textarea name="body" maxlength="10000" required>{{ old('body') }}</textarea></label>
        <label>{{ __('app.attach_file') }}<input type="file" name="attachments[]" multiple accept="application/pdf,image/jpeg,image/png"></label>
        <p class="muted">{{ __('app.message_attachment_hint') }}</p>
        <button type="submit">{{ __('app.send') }}</button>
    </form>
</section>
@endcan
@endsection
