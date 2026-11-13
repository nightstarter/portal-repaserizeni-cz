@extends('layouts.app')
@section('content')
<section class="card mobile-hero">
    <p>{{ __('app.shipment') }}</p><h1>{{ $shipment->public_number }}</h1>
    <div class="meta"><span>{{ __('app.shipment_directions.'.$shipment->direction->value) }}</span><span>{{ __('app.shipment_carriers.'.$shipment->carrier->value) }}</span><span class="badge">{{ __('app.shipment_statuses.'.$shipment->status->value) }}</span></div>
</section>

<div class="split">
<section class="card">
    <h2>{{ __('app.shipment_details') }}</h2>
    <dl class="branch-facts">
        <div><dt>{{ __('app.sender') }}</dt><dd>{{ $shipment->senderOrganization->name }}</dd></div>
        <div><dt>{{ __('app.recipient') }}</dt><dd>{{ $shipment->recipientOrganization->name }}</dd></div>
        @if($shipment->carrier->requiresTracking())<div><dt>{{ __('app.tracking') }}</dt><dd>{{ $shipment->tracking ?: '—' }}</dd></div>@endif
        <div><dt>{{ __('app.created_by') }}</dt><dd>{{ $shipment->creator->name }}</dd></div>
        @if($shipment->sent_at)<div><dt>{{ __('app.sent_at') }}</dt><dd>{{ $shipment->sent_at->format('d.m.Y H:i') }}</dd></div>@endif
        @if($shipment->delivered_at)<div><dt>{{ __('app.delivered_at') }}</dt><dd>{{ $shipment->delivered_at->format('d.m.Y H:i') }}</dd></div>@endif
    </dl>
    @if($shipment->transport_error_text)<div class="notice"><strong>{{ __('app.transport_error') }}</strong><p>{{ $shipment->transport_error_text }}</p></div>@endif
</section>

@if($canEdit)
<section class="card action-card">
    <h2>{{ __('app.scan_items') }}</h2>
    <p>{{ __('app.scan_items_intro') }}</p>
    <form id="shipment-scan-form" method="post" action="{{ route('shipments.items.store', $shipment) }}">@csrf
        <input type="hidden" name="idempotency_key" value="{{ $formKeys['add'] }}">
        <input type="hidden" name="expected_lock_version" value="{{ $shipment->lock_version }}">
        <label for="qr_token">{{ __('app.qr_code') }}</label>
        <input id="qr_token" name="qr_token" required autofocus autocomplete="off" placeholder="{{ __('app.scan_or_enter_qr') }}">
        <button>{{ __('app.add_to_shipment') }}</button>
        <button type="button" id="start-camera" class="button compact">{{ __('app.start_camera') }}</button>
    </form>
    <video id="qr-camera" hidden playsinline style="width:100%;margin-top:1rem;border-radius:.4rem"></video>
    <p id="camera-message" class="muted"></p>
</section>
@endif
</div>

<section class="card table-scroll">
    <h2>{{ __('app.shipment_items') }} ({{ $shipment->shipmentItems->count() }})</h2>
    <table class="responsive"><thead><tr><th>{{ __('app.order') }}</th><th>{{ __('app.item_role') }}</th><th>{{ __('app.related_branch') }}</th><th>{{ __('app.location') }}</th><th></th></tr></thead><tbody>
    @forelse($shipment->shipmentItems as $link)
        <tr>
            <td data-label="{{ __('app.order') }}"><a href="{{ route('orders.show', $link->item->order) }}">#{{ $link->item->order->order_no }}</a><br>{{ $link->item->order->product_code }}</td>
            <td data-label="{{ __('app.item_role') }}">{{ __('app.item_roles.'.$link->item->role->value) }}</td>
            <td data-label="{{ __('app.related_branch') }}">{{ __('app.thread_types.'.$link->processThread->type->value) }}</td>
            <td data-label="{{ __('app.location') }}">{{ __('app.locations.'.$link->item->current_location) }}</td>
            <td>@if($canEdit)<form method="post" action="{{ route('shipments.items.destroy', [$shipment, $link->item]) }}">@csrf @method('DELETE')<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="expected_lock_version" value="{{ $shipment->lock_version }}"><button class="danger compact">{{ __('app.remove') }}</button></form>@endif</td>
        </tr>
    @empty<tr><td colspan="5">{{ __('app.shipment_empty') }}</td></tr>@endforelse
    </tbody></table>
</section>

@if($canEdit || $canReceive || $canReportError)
<section class="card action-card"><h2>{{ __('app.shipment_actions') }}</h2>
    @if($canEdit)
    <form method="post" action="{{ route('shipments.actions', [$shipment, \App\Enums\ShipmentAction::SEND_SHIPMENT->value]) }}">@csrf
        <input type="hidden" name="idempotency_key" value="{{ $formKeys['send'] }}"><input type="hidden" name="expected_lock_version" value="{{ $shipment->lock_version }}">
        @if($shipment->carrier->requiresTracking())<label for="send_tracking">{{ __('app.tracking') }}</label><input id="send_tracking" name="tracking" value="{{ $shipment->tracking }}" required maxlength="128">@endif
        <button>{{ __('app.send_shipment') }}</button>
    </form>
    @endif
    @if($canReceive)
    <form class="inline" method="post" action="{{ route('shipments.actions', [$shipment, \App\Enums\ShipmentAction::CONFIRM_SHIPMENT_DELIVERY->value]) }}">@csrf<input type="hidden" name="idempotency_key" value="{{ $formKeys['deliver'] }}"><input type="hidden" name="expected_lock_version" value="{{ $shipment->lock_version }}"><button>{{ __('app.confirm_delivery') }}</button></form>
    @endif
    @if($canReportError)
    <form method="post" action="{{ route('shipments.actions', [$shipment, \App\Enums\ShipmentAction::MARK_TRANSPORT_ERROR->value]) }}">@csrf<input type="hidden" name="idempotency_key" value="{{ $formKeys['error'] }}"><input type="hidden" name="expected_lock_version" value="{{ $shipment->lock_version }}"><label for="description">{{ __('app.transport_error_description') }}</label><textarea id="description" name="description" required maxlength="5000"></textarea><button class="danger">{{ __('app.mark_transport_error') }}</button></form>
    @endif
</section>
@endif

<section class="card"><h2>{{ __('app.shipment_history') }}</h2><ol class="timeline">
@foreach($shipment->events as $event)<li><h3>{{ __('app.shipment_actions_labels.'.$event->action) }}</h3><p>{{ $event->performed_at->format('d.m.Y H:i') }} · {{ $event->actorUser->name }}</p><span class="badge">{{ __('app.shipment_statuses.'.$event->to_status) }}</span></li>@endforeach
</ol></section>

@if($canEdit)
<script>
(() => {
    const button = document.getElementById('start-camera');
    const video = document.getElementById('qr-camera');
    const input = document.getElementById('qr_token');
    const form = document.getElementById('shipment-scan-form');
    const message = document.getElementById('camera-message');
    if (!button || !('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
        if (button) button.hidden = true;
        return;
    }
    button.addEventListener('click', async () => {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});
            video.srcObject = stream; video.hidden = false; await video.play();
            const detector = new BarcodeDetector({formats:['qr_code']});
            const scan = async () => {
                const codes = await detector.detect(video);
                if (codes.length) {
                    stream.getTracks().forEach(track => track.stop());
                    input.value = codes[0].rawValue; form.submit(); return;
                }
                requestAnimationFrame(scan);
            };
            scan();
        } catch (error) { message.textContent = @json(__('app.camera_unavailable')); }
    });
})();
</script>
@endif
@endsection
