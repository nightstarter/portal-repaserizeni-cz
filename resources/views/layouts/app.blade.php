<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light; font-family: Inter,system-ui,sans-serif; --blue:#174b78; --blue-dark:#103653; --red:#a21d2b; --green:#187443; --ink:#1d2935; }
        * { box-sizing:border-box } body { margin:0; background:#f3f5f7; color:#1d2935 }
        nav { background:var(--blue); color:white; padding:.8rem 1.2rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap }
        nav a { color:white } nav .spacer { flex:1 }
        main { max-width:1100px; margin:2rem auto; padding:0 1rem }
        .card { background:white; border-radius:.5rem; padding:1.25rem; box-shadow:0 1px 5px #0002; margin-bottom:1rem }
        label { display:block; font-weight:600; margin-top:.8rem } input, select, textarea { width:100%; padding:.7rem; border:1px solid #adb5bd; border-radius:.35rem; font:inherit; background:white }
        textarea { min-height:6rem; resize:vertical } input[type=checkbox],input[type=radio] { width:auto } button,.button { display:inline-block; background:var(--blue); color:white; border:0; border-radius:.35rem; padding:.7rem 1rem; cursor:pointer; text-decoration:none; margin-top:1rem; font-weight:650 }
        button:hover,.button:hover { background:var(--blue-dark) }
        .danger { background:var(--red) } .error { color:var(--red) } .status { background:#d9f2df; padding:.75rem; border-radius:.3rem }
        table { width:100%; border-collapse:collapse } th,td { padding:.6rem; border-bottom:1px solid #ddd; text-align:left; vertical-align:top }
        .inline { display:inline } .inline button { margin:0 } .narrow { max-width:30rem; margin:auto }
        .kpis,.cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(12rem,1fr)); gap:1rem; margin-bottom:1rem }
        .kpi strong { display:block; font-size:1.8rem } .muted { color:#627180 }
        .badge { display:inline-block; border-radius:999px; background:#e4edf5; padding:.25rem .55rem; margin:.1rem; font-size:.86rem }
        .badge.waiting { background:#fff0c2 } .badge.done { background:#d9f2df }
        .meta { display:flex; gap:1rem; flex-wrap:wrap } .meta span { min-width:10rem }
        .action-card { border:2px solid #b7cee1; background:#f8fbfe } .action-card h2 { margin-top:0 }
        .primary-action button { width:100%; min-height:3.25rem; font-size:1.05rem }
        .choice-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(11rem,1fr)); gap:.55rem }
        .choice { display:flex; gap:.5rem; align-items:center; padding:.7rem; border:1px solid #ccd5dc; border-radius:.4rem; margin:0; background:white }
        .choice input { flex:0 0 auto } .choice span { flex:1 }
        .timeline { list-style:none; padding:0; margin:0 } .timeline li { position:relative; padding:0 0 1.3rem 1.5rem; border-left:2px solid #c7d6e2; margin-left:.35rem }
        .timeline li::before { content:""; position:absolute; width:.7rem; height:.7rem; border-radius:50%; background:var(--blue); left:-.42rem; top:.25rem }
        .timeline li:last-child { border-left-color:transparent } .timeline h3 { margin:.1rem 0 .3rem; font-size:1rem }
        .timeline-meta { display:flex; gap:.5rem; flex-wrap:wrap; margin-top:.4rem } .timeline-meta span { background:#edf2f6; border-radius:.25rem; padding:.2rem .4rem; font-size:.84rem }
        .mobile-hero { background:linear-gradient(145deg,var(--blue),#276c9e); color:white } .mobile-hero .badge { color:var(--ink); background:white }
        .split { display:grid; grid-template-columns:repeat(auto-fit,minmax(18rem,1fr)); gap:1rem }
        .table-scroll { overflow-x:auto }
        .queue-nav { display:flex; gap:.5rem; overflow-x:auto; padding:.2rem 0 1rem; margin-bottom:.5rem }
        .queue-link { flex:0 0 auto; color:var(--blue); background:white; border:1px solid #b7cee1; border-radius:999px; padding:.55rem .8rem; text-decoration:none }
        .queue-link.active { color:white; background:var(--blue); border-color:var(--blue) }
        .button.compact { padding:.45rem .65rem; margin:0; font-size:.88rem }
        .branch-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(19rem,1fr)); gap:1rem; align-items:start }
        .branch-card { border-top:4px solid #d79b19 } .branch-card.completed { border-top-color:var(--green) }
        .branch-card header { display:flex; justify-content:space-between; gap:.75rem; align-items:start }
        .branch-card h3 { margin:.1rem 0 .7rem } .branch-facts { margin:.7rem 0 }
        .branch-facts div { display:grid; grid-template-columns:minmax(8rem,1fr) 1.4fr; gap:.6rem; padding:.4rem 0; border-bottom:1px solid #edf0f2 }
        .branch-facts dt { color:#627180; font-weight:650 } .branch-facts dd { margin:0 }
        .branch-actions { border:1px solid #b7cee1; border-radius:.4rem; padding:.85rem; background:#f8fbfe }
        .branch-actions h3 { font-size:1rem } .notice { background:#fff4d6; border-left:4px solid #d79b19; border-radius:.25rem; padding:.8rem }
        .closure-card ul { margin-bottom:0 }
        @media (max-width:700px) {
            main { margin:1rem auto; padding:0 .7rem } .card { padding:1rem }
            table.responsive thead { display:none } table.responsive tr { display:block; margin-bottom:1rem; border:1px solid #ddd; border-radius:.4rem; padding:.35rem }
            table.responsive td { display:block; border:0; padding:.35rem .45rem } table.responsive td::before { content:attr(data-label); display:block; color:#627180; font-size:.75rem; text-transform:uppercase; font-weight:700 }
            .mobile-primary { order:-1 } .primary-action button { min-height:3.8rem; font-size:1.1rem }
            .branch-grid { grid-template-columns:1fr } .branch-card { scroll-margin-top:1rem }
        }
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
