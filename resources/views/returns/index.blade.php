@extends('layouts.app')
@section('content')
<h1>{{ __('app.returns') }}</h1>
<p class="muted">{{ __('app.returns_intro') }}</p>

<div class="queue-nav page-tabs" role="navigation" aria-label="{{ __('app.return_filters_label') }}">
@foreach($filters as $filter)
    <a class="queue-link {{ $activeFilter === $filter ? 'active' : '' }}" href="{{ route('returns.index', $filter === 'all' ? [] : ['filter' => $filter]) }}" @if($activeFilter === $filter) aria-current="page" @endif>
        {{ __('app.return_filters.'.$filter) }} <strong>{{ $filterCounts[$filter] }}</strong>
    </a>
@endforeach
</div>

<section class="card">
    <div class="table-scroll"><table class="responsive">
        <thead><tr>
            <th>{{ __('app.order_no') }} / {{ __('app.product') }}</th>
            <th>{{ __('app.state') }}</th>
            <th>{{ __('app.location') }}</th>
            <th>{{ __('app.next_responsibility') }}</th>
            <th>{{ __('app.supplier_deposit_status') }}</th>
            <th>{{ __('app.next_action') }}</th>
        </tr></thead>
        <tbody>
        @forelse($rows as $row)
            @php($thread = $row['thread'])
            @php($order = $row['order'])
            <tr>
                <td data-label="{{ __('app.order_no') }} / {{ __('app.product') }}"><a href="{{ route('orders.show', $order) }}#thread-{{ $thread->id }}"><strong>#{{ $order->order_no }}</strong></a><br><span class="muted">{{ $order->product_code }}</span></td>
                <td data-label="{{ __('app.state') }}"><span class="badge {{ $thread->completed_at ? 'done' : 'waiting' }}">{{ __('app.thread_states.'.$thread->state) }}</span></td>
                <td data-label="{{ __('app.location') }}">{{ $row['item'] ? __('app.locations.'.$row['item']->current_location) : '—' }}</td>
                <td data-label="{{ __('app.next_responsibility') }}">{{ $thread->responsibleOrganization?->name ?? __('app.responsible_parties.'.$thread->responsible_party->value) }}</td>
                <td data-label="{{ __('app.supplier_deposit_status') }}">{{ __('app.supplier_deposit_statuses.'.$row['deposit_status']) }}@if($row['deposit_amount'])<br><span class="muted">{{ $row['deposit_amount'] }} {{ $row['deposit_currency'] }}</span>@endif</td>
                <td data-label="{{ __('app.next_action') }}"><a class="button compact" href="{{ route('orders.show', $order) }}#thread-{{ $thread->id }}">{{ __('app.return_next_actions.'.$row['next_action']) }}</a></td>
            </tr>
        @empty
            <tr><td colspan="6"><div class="empty-state"><strong>{{ __('app.no_returns') }}</strong><p class="muted">{{ __('app.no_returns_hint') }}</p></div></td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
