@extends('layouts.app')
@section('content')
<div class="card narrow">
    <h1>{{ __('app.change_password') }}</h1>
    <form method="post" action="{{ route('password.update') }}">@csrf @method('PUT')
        <label>{{ __('app.current_password') }}</label><input type="password" name="current_password" autocomplete="current-password" required>
        <label>{{ __('app.new_password') }}</label><input type="password" name="password" autocomplete="new-password" required>
        <label>{{ __('app.password_confirmation') }}</label><input type="password" name="password_confirmation" autocomplete="new-password" required>
        <button>{{ __('app.save') }}</button>
    </form>
</div>
@endsection
