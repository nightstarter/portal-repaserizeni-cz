<section class="branch-actions warranty-actions">
    <h4>{{ __('app.what_now') }}</h4>
    @forelse($warranty['allowed_actions'] as $action)
        @php($identity = 'warranty-'.$warranty['thread']->id.'-'.$action->value)
        <form class="primary-action" method="post" data-submit-once action="{{ route('orders.warranty.actions', [$order, $warranty['thread'], $action->value]) }}">
            @csrf
            <input type="hidden" name="submitted_action" value="{{ $identity }}">
            <input type="hidden" name="idempotency_key" value="{{ old('submitted_action') === $identity ? old('idempotency_key') : (string) \Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="expected_lock_version" value="{{ $warranty['thread']->lock_version }}">
            @if($qrMode ?? false)<input type="hidden" name="return_qr_token" value="{{ $selectedItem->qr_token }}">@endif
            @if(in_array($action, [\App\Enums\WarrantyAction::SEND_TO_CUSTOMER, \App\Enums\WarrantyAction::CONFIRM_CUSTOMER_PICKUP], true))
                <label class="choice"><input type="checkbox" name="confirmed" value="1" required><span>{{ __('app.confirm_irreversible_warranty_action') }}</span></label>
            @endif
            <button type="submit">{{ __('app.warranty_actions.'.$action->value) }}</button>
        </form>
    @empty
        @if($warranty['transport_action'])
            @php($transportShipment = $warranty['shipments']->sortByDesc('id')->first())
            <p><strong>{{ __('app.warranty_actions.'.$warranty['transport_action']->value) }}</strong></p>
            @if($transportShipment && in_array($transportShipment->status->value, ['PREPARED', 'SENT', 'TRANSPORT_ERROR'], true))
                <a class="button" href="{{ route('shipments.show', $transportShipment) }}">{{ __('app.open_related_shipment') }}</a>
            @else
                <a class="button" href="{{ route('shipments.create') }}">{{ __('app.open_shipment_workflow') }}</a>
            @endif
            <p class="muted">{{ __('app.warranty_shipment_action_hint') }}</p>
        @else
            <p class="muted">{{ $warranty['thread']->completed_at ? __('app.warranty_completed') : __('app.no_action') }}</p>
        @endif
    @endforelse
</section>
