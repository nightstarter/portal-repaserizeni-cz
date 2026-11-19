@extends('layouts.app')
@section('content')
<div class="section-heading"><div><h1>{{ __('app.buybacks') }}</h1><p class="muted">{{ __('app.buybacks_intro') }}</p></div>
@if(auth()->user()->organization->code === \App\Enums\OrganizationCode::AUTOKLUBY && auth()->user()->role !== \App\Enums\UserRole::READ_ONLY)
<a class="button" href="{{ route('buybacks.create') }}">{{ __('app.buyback_new') }}</a>@endif</div>
<div class="queue-nav">@foreach($allowed as $code)<a class="queue-link {{ $filter === $code ? 'active' : '' }}" href="{{ route('buybacks.index', ['filter' => $code]) }}">{{ __('app.buyback_filters.'.$code) }}</a>@endforeach</div>
<div class="card"><div class="table-scroll"><table class="responsive"><thead><tr>
<th>{{ __('app.order_no') }}</th><th>{{ __('app.state') }}</th><th>{{ __('app.product') }}</th><th>{{ __('app.next_responsibility') }}</th><th>{{ __('app.last_event') }}</th><th>{{ __('app.next_action') }}</th>@if($isAk)<th>{{ __('app.buyback_customer_offer') }}</th>@endif<th>{{ __('app.shipment') }}</th><th>{{ __('app.documents_finance') }}</th>
</tr></thead><tbody>
@forelse($rows as $row) @php($thread=$row['thread']) @php($order=$thread->order)
<tr>
<td data-label="{{ __('app.order_no') }}"><a href="{{ route('orders.show', $order) }}">#{{ $order->order_no }}</a></td>
<td data-label="{{ __('app.state') }}"><span class="badge {{ $thread->completed_at ? 'done' : 'waiting' }}">{{ __('app.thread_states.'.$row['display_state']) }}</span></td>
<td data-label="{{ __('app.product') }}">#{{ $thread->item_id }} · {{ $order->product_code }}<br>{{ $order->technical_type ? __('app.technical_types.'.$order->technical_type->value) : '—' }}</td>
<td data-label="{{ __('app.next_responsibility') }}">{{ $thread->responsibleOrganization?->name ?? __('app.responsible_parties.'.$thread->responsible_party->value) }}</td>
<td data-label="{{ __('app.last_event') }}">{{ $thread->latestEvent?->performed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '—' }}</td>
<td data-label="{{ __('app.next_action') }}">{{ $row['next_action'] ? __('app.buyback_actions.'.$row['next_action']->value) : '—' }}</td>
@if($isAk)<td data-label="{{ __('app.buyback_customer_offer') }}">{{ __('app.buyback_offer_states.'.$row['state']->value) }}</td>@endif
<td data-label="{{ __('app.shipment') }}">{{ $row['shipments']->last()?->status?->value ? __('app.shipment_statuses.'.$row['shipments']->last()->status->value) : '—' }}</td>
<td data-label="{{ __('app.documents_finance') }}">{{ $row['documents']->where('status', \App\Enums\DocumentStatus::ACTIVE)->isNotEmpty() ? __('app.buyback_invoice_present') : '—' }} @if($row['is_ak'] && $row['finance_entries']->isNotEmpty()) · {{ __('app.buyback_paid') }} @endif</td>
</tr>
@empty<tr><td colspan="{{ $isAk ? 9 : 8 }}"><div class="empty-state"><strong>{{ __('app.buyback_empty') }}</strong><p>{{ __('app.buyback_empty_hint') }}</p></div></td></tr>@endforelse
</tbody></table></div></div>{{ $threads->links() }}
@endsection
