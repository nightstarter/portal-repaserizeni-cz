@extends('layouts.app')
@section('content')
<h1>{{ __('app.discussion') }}</h1>
<p class="muted">{{ __('app.discussions_intro') }}</p>

<section class="card">
    <div class="table-scroll"><table class="responsive">
        <thead><tr><th>{{ __('app.order_no') }}</th><th>{{ __('app.last_message') }}</th><th>{{ __('app.author') }}</th><th>{{ __('app.date_time') }}</th><th>{{ __('app.unread') }}</th><th>{{ __('app.open') }}</th></tr></thead>
        <tbody>
        @forelse($orders as $order)
            @php($message = $order->latestMessage)
            <tr class="{{ $order->unread_count > 0 ? 'discussion-unread' : '' }}">
                <td data-label="{{ __('app.order_no') }}"><strong>#{{ $order->order_no }}</strong><br><span class="muted">{{ $order->product_code }}</span></td>
                <td class="message-preview" data-label="{{ __('app.last_message') }}">{{ \Illuminate\Support\Str::limit($message->body, 180) }}</td>
                <td data-label="{{ __('app.author') }}">{{ $message->author->name }}<br><span class="muted">{{ $message->authorOrganization->name }}</span></td>
                <td data-label="{{ __('app.date_time') }}"><time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</time></td>
                <td data-label="{{ __('app.unread') }}">@if($order->unread_count > 0)<span class="unread-badge">{{ $order->unread_count }}</span>@else — @endif</td>
                <td data-label="{{ __('app.open') }}"><a class="button compact" href="{{ route('orders.discussion.index', $order) }}">{{ __('app.open_discussion') }}</a></td>
            </tr>
        @empty
            <tr><td colspan="6"><div class="empty-state"><strong>{{ __('app.no_discussions') }}</strong><p class="muted">{{ __('app.no_discussions_hint') }}</p></div></td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

@if($orders->hasPages())
<nav class="pagination" aria-label="{{ __('app.pagination') }}">
    @if($orders->previousPageUrl())<a class="button compact" href="{{ $orders->previousPageUrl() }}">{{ __('app.previous') }}</a>@endif
    @if($orders->nextPageUrl())<a class="button compact" href="{{ $orders->nextPageUrl() }}">{{ __('app.next') }}</a>@endif
</nav>
@endif
@endsection
