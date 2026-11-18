@extends('layouts.app')
@section('content')
<h1>{{ __('app.documents_finance') }}</h1>
<p class="muted">{{ __('app.finance_intro') }}</p>

@foreach($sections as $code => $rows)
<section class="card finance-queue" id="{{ str_replace('_', '-', $code) }}">
    <header class="section-heading"><div><h2>{{ __('app.finance_queues.'.$code) }}</h2><p class="muted">{{ __('app.finance_queue_hints.'.$code) }}</p></div><strong class="queue-count">{{ $rows->count() }}</strong></header>
    <div class="table-scroll"><table class="responsive">
        <thead><tr><th>{{ __('app.order_no') }}</th><th>{{ __('app.product') }}</th><th>{{ __('app.branch') }}</th><th>{{ __('app.state') }}</th><th>{{ __('app.supplier_deposit_status') }}</th><th>{{ __('app.open') }}</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            @php($thread = $row['thread'])
            @php($order = $row['order'])
            <tr>
                <td data-label="{{ __('app.order_no') }}"><strong>#{{ $order->order_no }}</strong></td>
                <td data-label="{{ __('app.product') }}">{{ $order->product_code }}</td>
                <td data-label="{{ __('app.branch') }}">{{ __('app.thread_types.'.$thread->type->value) }}</td>
                <td data-label="{{ __('app.state') }}">{{ __('app.thread_states.'.$thread->state) }}</td>
                <td data-label="{{ __('app.supplier_deposit_status') }}">{{ __('app.supplier_deposit_statuses.'.$row['deposit_status']) }}@if($row['deposit_amount'])<br><span class="muted">{{ $row['deposit_amount'] }} {{ $row['deposit_currency'] }}</span>@endif</td>
                <td data-label="{{ __('app.open') }}"><a class="button compact" href="{{ route('orders.show', $order) }}#finance">{{ __('app.open_order_finance') }}</a></td>
            </tr>
        @empty
            <tr><td colspan="6"><div class="empty-state"><strong>{{ __('app.finance_queue_empty') }}</strong><p class="muted">{{ __('app.finance_queue_empty_hint') }}</p></div></td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endforeach
@endsection
