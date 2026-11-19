@extends('layouts.app')
@section('content')
<div class="section-heading">
    <div><h1>{{ __('app.warranties') }}</h1><p class="muted">{{ __('app.warranties_intro') }}</p></div>
</div>
<div class="queue-nav" role="navigation" aria-label="{{ __('app.warranty_filters_label') }}">
@foreach($filters as $filter)
    <a class="queue-link {{ $activeFilter === $filter ? 'active' : '' }}" href="{{ route('warranties.index', ['filter' => $filter]) }}">{{ __('app.warranty_filters.'.$filter) }}</a>
@endforeach
</div>
<div class="card">
<div class="table-scroll"><table class="responsive"><thead><tr>
    <th>{{ __('app.order_no') }} / {{ __('app.warranty_cycle') }}</th>
    <th>{{ __('app.physical_item') }}</th>
    <th>{{ __('app.technical_type') }} / {{ __('app.routing') }}</th>
    <th>{{ __('app.state') }} / {{ __('app.next_responsibility') }}</th>
    <th>{{ __('app.shipment') }}</th>
    <th>{{ __('app.last_event') }}</th>
    <th>{{ __('app.next_action') }}</th>
</tr></thead><tbody>
@forelse($rows as $row)
    @php($thread = $row['thread']) @php($order = $thread->order) @php($shipment = $row['active_shipment'])
    <tr>
        <td data-label="{{ __('app.order_no') }} / {{ __('app.warranty_cycle') }}"><a href="{{ route('orders.show', $order) }}#warranty-thread-{{ $thread->id }}"><strong>#{{ $order->order_no }}</strong></a><br>{{ __('app.warranty_number', ['number' => $thread->warranty_cycle]) }}</td>
        <td data-label="{{ __('app.physical_item') }}">{{ __('app.item_identifier', ['id' => $thread->item_id]) }}<br><span class="muted">{{ __('app.item_roles.'.$thread->item->role->value) }}</span></td>
        <td data-label="{{ __('app.technical_type') }} / {{ __('app.routing') }}">{{ $order->technical_type ? __('app.technical_types.'.$order->technical_type->value) : '—' }}<br><span class="badge">{{ $order->routing?->value ?: '—' }}</span></td>
        <td data-label="{{ __('app.state') }} / {{ __('app.next_responsibility') }}"><span class="badge {{ $thread->completed_at ? 'done' : 'waiting' }}">{{ __('app.thread_states.'.$thread->state) }}</span><br>{{ $thread->responsibleOrganization?->name ?? __('app.responsible_parties.'.$thread->responsible_party->value) }}</td>
        <td data-label="{{ __('app.shipment') }}">@if($shipment)<a href="{{ route('shipments.show', $shipment) }}">{{ $shipment->public_number }}</a>@else — @endif</td>
        <td data-label="{{ __('app.last_event') }}">{{ $row['last_event']?->performed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?: '—' }}</td>
        <td data-label="{{ __('app.next_action') }}">@if($row['next_action'])<a class="button compact" href="{{ route('orders.show', $order) }}#warranty-thread-{{ $thread->id }}">{{ __('app.warranty_actions.'.$row['next_action']->value) }}</a>@else — @endif</td>
    </tr>
@empty
    <tr><td colspan="7"><div class="empty-state"><strong>{{ __('app.no_warranties') }}</strong><p class="muted">{{ __('app.no_warranties_hint') }}</p></div></td></tr>
@endforelse
</tbody></table></div>
</div>
@if($threads->hasPages())<nav class="pagination">{{ $threads->links() }}</nav>@endif
@endsection
