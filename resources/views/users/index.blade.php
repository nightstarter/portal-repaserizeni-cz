@extends('layouts.app')
@section('content')
<div class="card">
    <h1>{{ __('app.users') }}</h1>
    <table><thead><tr><th>{{ __('app.name') }}</th><th>{{ __('auth.email') }}</th><th>{{ __('app.organization') }}</th><th>{{ __('app.role') }}</th><th>{{ __('app.active') }}</th></tr></thead><tbody>
    @foreach($users as $user)<tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $user->organization->name }}</td><td>{{ $user->role->value }}</td><td>{{ $user->active ? __('app.yes') : __('app.no') }}</td></tr>@endforeach
    </tbody></table>
</div>
<div class="card">
    <h2>{{ __('app.create_user') }}</h2>
    <form method="post" action="{{ route('users.store') }}">@csrf
        <label>{{ __('app.name') }}</label><input name="name" required>
        <label>{{ __('auth.email') }}</label><input type="email" name="email" required>
        <label>{{ __('app.organization') }}</label><select name="organization_id">@foreach($organizations as $organization)<option value="{{ $organization->id }}">{{ $organization->name }}</option>@endforeach</select>
        <label>{{ __('app.role') }}</label><select name="role">@foreach($roles as $role)<option value="{{ $role->value }}">{{ $role->value }}</option>@endforeach</select>
        <label>{{ __('app.language') }}</label><select name="preferred_locale"><option value="cs">CZ</option><option value="pl">PL</option></select>
        <label>{{ __('auth.password') }}</label><input type="password" name="password" required>
        <label>{{ __('app.password_confirmation') }}</label><input type="password" name="password_confirmation" required>
        <button>{{ __('app.create_user') }}</button>
    </form>
</div>
@endsection
