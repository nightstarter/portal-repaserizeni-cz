@extends('layouts.app')
@section('content')
<h1>{{ __('app.notification_admin') }}</h1>

@unless($deliveryEnabled)
<div class="notice"><strong>{{ __('app.notification_delivery_disabled') }}</strong><br>{{ __('app.notification_delivery_disabled_hint') }}</div>
@endunless

<div class="kpis">
    @foreach([\App\Models\OutboxEvent::PENDING, \App\Models\OutboxEvent::PROCESSING, \App\Models\OutboxEvent::SENT, \App\Models\OutboxEvent::FAILED] as $status)
    <div class="card kpi"><span>{{ __('app.notification_status_'.strtolower($status)) }}</span><strong>{{ $counts[$status] }}</strong></div>
    @endforeach
</div>

<section class="card">
    <p><strong>{{ __('app.notification_last_sent') }}:</strong> {{ $lastSentAt ? \Illuminate\Support\Carbon::parse($lastSentAt)->timezone(config('app.timezone'))->format('d.m.Y H:i') : '—' }}</p>
    <div class="table-scroll"><table class="responsive">
        <thead><tr><th>{{ __('app.order_no') }}</th><th>{{ __('app.notification_recipient') }}</th><th>{{ __('app.status') }}</th><th>{{ __('app.notification_attempts') }}</th><th>{{ __('app.notification_next_attempt') }}</th><th>{{ __('app.notification_error') }}</th><th>{{ __('app.notification_actions') }}</th></tr></thead>
        <tbody>
        @forelse($events as $event)
            @php
                $email = $event->recipient?->email ?? '';
                [$local, $domain] = str_contains($email, '@') ? explode('@', $email, 2) : [$email, ''];
                $masked = $email === '' ? '—' : mb_substr($local, 0, 1).'***@'.mb_substr($domain, 0, 1).'***';
            @endphp
            <tr>
                <td data-label="{{ __('app.order_no') }}">@if($event->message?->order)<a href="{{ route('orders.discussion.index', $event->message->order) }}">#{{ $event->message->order->order_no }}</a>@else — @endif</td>
                <td data-label="{{ __('app.notification_recipient') }}">{{ $masked }}</td>
                <td data-label="{{ __('app.status') }}">{{ __('app.notification_status_'.strtolower($event->status)) }}</td>
                <td data-label="{{ __('app.notification_attempts') }}">{{ $event->attempts }}</td>
                <td data-label="{{ __('app.notification_next_attempt') }}">{{ $event->next_attempt_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '—' }}</td>
                <td class="message-preview" data-label="{{ __('app.notification_error') }}">{{ $event->last_error ?: '—' }}</td>
                <td data-label="{{ __('app.notification_actions') }}">
                    @if($event->status === \App\Models\OutboxEvent::FAILED && $event->recipient_user_id !== null)
                    <form method="post" action="{{ route('admin.notifications.retry', $event) }}">@csrf<button class="compact">{{ __('app.notification_retry') }}</button></form>
                    @else — @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7">{{ __('app.notification_empty') }}</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
