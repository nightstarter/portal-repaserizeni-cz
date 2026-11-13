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
            @foreach(\App\Enums\ShipmentCarrier::cases() as $carrier)<option value="{{ $carrier->value }}" @selected(old('carrier') === $carrier->value)>{{ __('app.shipment_carriers.'.$carrier->value) }}</option>@endforeach
        </select>
        <label for="tracking">{{ __('app.tracking') }}</label>
        <input id="tracking" name="tracking" value="{{ old('tracking') }}" maxlength="128" placeholder="{{ __('app.tracking_send_hint') }}">
        @if(auth()->user()->role === \App\Enums\UserRole::AK_ADMIN)
            <label for="reason">{{ __('app.override_reason') }}</label>
            <textarea id="reason" name="reason" maxlength="2000">{{ old('reason') }}</textarea>
        @endif
        <button>{{ __('app.create_shipment') }}</button>
    </form>
</section>
@endsection
