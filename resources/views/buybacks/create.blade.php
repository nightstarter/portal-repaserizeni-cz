@extends('layouts.app')
@section('content')
<div class="card narrow"><h1>{{ __('app.buyback_new') }}</h1><p class="muted">{{ __('app.buyback_new_intro') }}</p>
<form method="post" action="{{ route('buybacks.store') }}" data-submit-once>@csrf
<input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
<label>{{ __('app.customer_name') }}<input name="customer_name" value="{{ old('customer_name') }}" required></label>
<label>{{ __('app.email') }}<input type="email" name="customer_email" value="{{ old('customer_email') }}" required></label>
<label>{{ __('app.phone') }}<input name="customer_phone" value="{{ old('customer_phone') }}"></label>
<label>{{ __('app.product_code') }}<input name="product_code" value="{{ old('product_code') }}" required></label>
<label>{{ __('app.product') }}<input name="product_name" value="{{ old('product_name') }}"></label>
<label>{{ __('app.technical_type') }}<select name="technical_type"><option value="">—</option>@foreach(\App\Enums\TechnicalType::cases() as $type)<option value="{{ $type->value }}">{{ __('app.technical_types.'.$type->value) }}</option>@endforeach</select></label>
<label>{{ __('app.routing') }}<select name="routing"><option value="">—</option>@foreach(\App\Enums\RoutingCode::cases() as $routing)<option value="{{ $routing->value }}">{{ $routing->value }}</option>@endforeach</select></label>
<label>{{ __('app.buyback_internal_note') }}<textarea name="internal_note">{{ old('internal_note') }}</textarea></label>
<div class="split"><label>{{ __('app.buyback_internal_offer') }}<input name="internal_offer_amount" inputmode="decimal" value="{{ old('internal_offer_amount') }}" required></label><label>{{ __('app.currency') }}<input name="internal_offer_currency" value="{{ old('internal_offer_currency', 'CZK') }}" maxlength="3" required></label></div>
<button type="submit">{{ __('app.buyback_create') }}</button></form></div>
@endsection
