@extends('layouts.app')
@section('content')
<div class="meta" style="align-items:center;justify-content:space-between">
    <div><h1>{{ __('app.shipments') }}</h1><p class="muted">{{ __('app.shipments_intro') }}</p></div>
    @if($canCreate)<a class="button" href="{{ route('shipments.create') }}">{{ __('app.new_shipment') }}</a>@endif
</div>
<section class="card table-scroll">
    <table class="responsive">
        <thead><tr><th>{{ __('app.shipment_number') }}</th><th>{{ __('app.shipment_direction') }}</th><th>{{ __('app.carrier') }}</th><th>{{ __('app.shipment_status') }}</th><th>{{ __('app.items_count') }}</th><th>{{ __('app.updated_at') }}</th><th></th></tr></thead>
        <tbody>
        @forelse($shipments as $shipment)
            <tr>
                <td data-label="{{ __('app.shipment_number') }}"><strong>{{ $shipment->public_number }}</strong></td>
                <td data-label="{{ __('app.shipment_direction') }}">{{ __('app.shipment_directions.'.$shipment->direction->value) }}</td>
                <td data-label="{{ __('app.carrier') }}">{{ __('app.shipment_carriers.'.$shipment->carrier->value) }}</td>
                <td data-label="{{ __('app.shipment_status') }}"><span class="badge {{ $shipment->status->value === 'DELIVERED' ? 'done' : ($shipment->status->value === 'TRANSPORT_ERROR' ? 'waiting' : '') }}">{{ __('app.shipment_statuses.'.$shipment->status->value) }}</span></td>
                <td data-label="{{ __('app.items_count') }}">{{ $shipment->shipment_items_count }}</td>
                <td data-label="{{ __('app.updated_at') }}">{{ $shipment->updated_at->format('d.m.Y H:i') }}</td>
                <td><a class="button compact" href="{{ route('shipments.show', $shipment) }}">{{ __('app.open') }}</a></td>
            </tr>
        @empty
            <tr><td colspan="7">{{ __('app.no_shipments') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</section>
{{ $shipments->links() }}
@endsection
