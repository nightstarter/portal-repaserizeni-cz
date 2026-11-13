@extends('layouts.app')
@section('content')
<section class="card narrow">
    <h1>{{ __('app.new_shipment') }}</h1>
    <p class="muted">{{ __('app.new_shipment_intro') }}</p>
    <form method="post" action="{{ route('shipments.store') }}">@csrf
        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        <label for="direction">{{ __('app.shipment_direction') }}</label>
        <select id="direction" name="direction" required>
            @foreach($directions as $direction)<option value="{{ $direction->value }}" @selected(old('direction') === $direction->value)>{{ __('app.shipment_directions.'.$direction->value) }}</option>@endforeach
        </select>
        <label for="carrier">{{ __('app.carrier') }}</label>
        <select id="carrier" name="carrier" required>
            @foreach(\App\Enums\ShipmentCarrier::cases() as $carrier)
                @php($carrierDirections = collect(\App\Enums\ShipmentDirection::cases())->filter(fn ($direction) => $direction->allowsCarrier($carrier))->pluck('value')->implode(' '))
                <option value="{{ $carrier->value }}" data-directions="{{ $carrierDirections }}" @selected(old('carrier') === $carrier->value)>{{ __('app.shipment_carriers.'.$carrier->value) }}</option>
            @endforeach
        </select>
        <div id="tracking-field">
            <label for="tracking">{{ __('app.tracking') }}</label>
            <input id="tracking" name="tracking" value="{{ old('tracking') }}" maxlength="128" placeholder="{{ __('app.tracking_send_hint') }}">
        </div>
        @if(auth()->user()->role === \App\Enums\UserRole::AK_ADMIN)
            <label for="reason">{{ __('app.override_reason') }}</label>
            <textarea id="reason" name="reason" maxlength="2000">{{ old('reason') }}</textarea>
        @endif
        <button>{{ __('app.create_shipment') }}</button>
    </form>
</section>
<script>
(() => {
    const direction = document.getElementById('direction');
    const carrier = document.getElementById('carrier');
    const trackingField = document.getElementById('tracking-field');
    const tracking = document.getElementById('tracking');
    const sync = () => {
        [...carrier.options].forEach(option => {
            const allowed = option.dataset.directions.split(' ').includes(direction.value);
            option.disabled = !allowed;
            option.hidden = !allowed;
        });
        if (carrier.selectedOptions[0]?.disabled) {
            carrier.value = [...carrier.options].find(option => !option.disabled)?.value ?? '';
        }
        const withoutTracking = carrier.value === @json(\App\Enums\ShipmentCarrier::SVOZ_AUTOKLUBY->value);
        trackingField.hidden = withoutTracking;
        tracking.disabled = withoutTracking;
        if (withoutTracking) tracking.value = '';
    };
    direction.addEventListener('change', sync);
    carrier.addEventListener('change', sync);
    sync();
})();
</script>
@endsection
