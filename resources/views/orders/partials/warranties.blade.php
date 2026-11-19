<section id="warranties" class="warranty-section">
<h2>{{ __('app.warranties') }}</h2>
@foreach($order->items as $item)
    @php($cycles = $warrantiesByItem->get($item->id, collect()))
    @php($activeCycle = $cycles->first(fn($row) => $row['thread']->completed_at === null))
    <div class="card warranty-item">
        <div class="section-heading"><div><h3>{{ __('app.item_identifier', ['id' => $item->id]) }}</h3><p class="muted">{{ __('app.item_roles.'.$item->role->value) }} · {{ __('app.locations.'.$item->current_location) }}</p></div></div>
        @if(auth()->user()->organization->code === \App\Enums\OrganizationCode::AUTOKLUBY && in_array(auth()->user()->role, [\App\Enums\UserRole::AK_ADMIN, \App\Enums\UserRole::AK_OPERATOR], true) && !$activeCycle && $item->active_shipment_id === null)
            @php($identity = 'warranty-create-'.$item->id)
            <details class="warranty-create"><summary>{{ __('app.create_warranty') }}</summary>
            <form method="post" data-submit-once action="{{ route('orders.warranty.store', [$order, $item]) }}">
                @csrf
                <input type="hidden" name="submitted_action" value="{{ $identity }}">
                <input type="hidden" name="idempotency_key" value="{{ old('submitted_action') === $identity ? old('idempotency_key') : $warrantyCreateKeys[$item->id] }}">
                <input type="hidden" name="expected_lock_version" value="{{ $item->warranty_lock_version }}">
                <label>{{ __('app.warranty_reason') }}<textarea name="reason" maxlength="2000" required>{{ old('submitted_action') === $identity ? old('reason') : '' }}</textarea></label>
                <p class="muted">{{ __('app.warranty_reason_private_hint') }}</p>
                <button type="submit">{{ __('app.create_warranty') }}</button>
            </form></details>
        @endif
        @if($cycles->isEmpty())<p class="muted">{{ __('app.no_item_warranties') }}</p>@else
            <div class="warranty-cycle-list">@foreach($cycles->sortByDesc(fn($row) => $row['thread']->warranty_cycle) as $warranty)@include('orders.partials.warranty-cycle', ['qrMode' => false])@endforeach</div>
        @endif
    </div>
@endforeach
</section>
