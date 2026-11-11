@extends('layouts.app')
@section('content')
<div class="card">
    <h1>{{ __('app.dashboard') }}</h1>
    <p>{{ __('app.phase_zero_ready') }}</p>
    <p><strong>{{ __('app.organization') }}:</strong> {{ auth()->user()->organization->name }} · <strong>{{ __('app.role') }}:</strong> {{ auth()->user()->role->value }}</p>
    <p>{{ __('app.import_disabled') }}</p>
    <a class="button" href="{{ route('password.edit') }}">{{ __('app.change_password') }}</a>
</div>
@endsection
