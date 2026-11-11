@extends('layouts.app')
@section('content')
<div class="card narrow">
    <h1>{{ __('auth.login') }}</h1>
    <form method="post" action="{{ route('login.store') }}">@csrf
        <label for="email">{{ __('auth.email') }}</label><input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        <label for="password">{{ __('auth.password') }}</label><input id="password" type="password" name="password" autocomplete="current-password" required>
        <label><input type="checkbox" name="remember" value="1"> {{ __('auth.remember') }}</label>
        <button type="submit">{{ __('auth.sign_in') }}</button>
    </form>
</div>
@endsection
