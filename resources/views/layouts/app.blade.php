<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; --blue:#174b78; --red:#a21d2b; }
        * { box-sizing:border-box } body { margin:0; background:#f3f5f7; color:#1d2935 }
        nav { background:var(--blue); color:white; padding:.8rem 1.2rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap }
        nav a { color:white } nav .spacer { flex:1 }
        main { max-width:1100px; margin:2rem auto; padding:0 1rem }
        .card { background:white; border-radius:.5rem; padding:1.25rem; box-shadow:0 1px 5px #0002; margin-bottom:1rem }
        label { display:block; font-weight:600; margin-top:.8rem } input, select { width:100%; padding:.6rem; border:1px solid #adb5bd; border-radius:.3rem }
        input[type=checkbox] { width:auto } button,.button { display:inline-block; background:var(--blue); color:white; border:0; border-radius:.3rem; padding:.65rem 1rem; cursor:pointer; text-decoration:none; margin-top:1rem }
        .danger { background:var(--red) } .error { color:var(--red) } .status { background:#d9f2df; padding:.75rem; border-radius:.3rem }
        table { width:100%; border-collapse:collapse } th,td { padding:.6rem; border-bottom:1px solid #ddd; text-align:left; vertical-align:top }
        .inline { display:inline } .inline button { margin:0 } .narrow { max-width:30rem; margin:auto }
        .kpis,.cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(12rem,1fr)); gap:1rem; margin-bottom:1rem }
        .kpi strong { display:block; font-size:1.8rem } .muted { color:#627180 }
        .badge { display:inline-block; border-radius:999px; background:#e4edf5; padding:.25rem .55rem; margin:.1rem; font-size:.86rem }
        .badge.waiting { background:#fff0c2 } .badge.done { background:#d9f2df }
        .meta { display:flex; gap:1rem; flex-wrap:wrap } .meta span { min-width:10rem }
        @media (max-width:700px) { table.responsive thead { display:none } table.responsive tr { display:block; margin-bottom:1rem; border:1px solid #ddd; border-radius:.4rem } table.responsive td { display:block; border:0 } }
    </style>
</head>
<body>
@auth
<nav>
    <strong>{{ config('app.name') }}</strong>
    <a href="{{ route('dashboard') }}">{{ __('app.dashboard') }}</a>
    @can('manage-users')<a href="{{ route('users.index') }}">{{ __('app.users') }}</a>@endcan
    <span class="spacer"></span>
    <form class="inline" method="post" action="{{ route('locale.update') }}">@csrf @method('PUT')
        <button name="locale" value="cs">CZ</button><button name="locale" value="pl">PL</button>
    </form>
    <span>{{ auth()->user()->name }}</span>
    <form class="inline" method="post" action="{{ route('logout') }}">@csrf<button>{{ __('app.logout') }}</button></form>
</nav>
@endauth
<main>
    @if(session('status'))<p class="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="card error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
</body>
</html>
