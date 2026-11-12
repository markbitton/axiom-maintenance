<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1d4ed8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="manifest" href="/manifest.webmanifest">
    <title>@yield('title', 'Axiom Maintenance')</title>
    <style>
        :root { --bg:#f6f7f9; --card:#fff; --ink:#111827; --muted:#6b7280; --line:#e5e7eb; --brand:#1d4ed8; --red:#b91c1c; --amber:#b45309; --green:#047857; }
        * { box-sizing: border-box; }
        body { margin:0; font:15px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; background:var(--bg); color:var(--ink); }
        a { color:var(--brand); text-decoration:none; }
        header { background:var(--card); border-bottom:1px solid var(--line); padding:10px 16px; display:flex; gap:16px; align-items:center; flex-wrap:wrap; }
        header strong { margin-right:auto; }
        main { max-width:1100px; margin:0 auto; padding:16px; } main.wide { max-width:none; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:14px 16px; margin-bottom:14px; }
        .grid { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); }
        .stat b { display:block; font-size:26px; }
        .muted { color:var(--muted); font-size:13px; }
        table { width:100%; border-collapse:collapse; }
        th, td { text-align:left; padding:8px 6px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { font-size:12px; text-transform:uppercase; color:var(--muted); }
        .pill { display:inline-block; padding:1px 8px; border-radius:99px; font-size:12px; background:#eef2ff; color:#3730a3; }
        .pill.emergency { background:#fee2e2; color:var(--red); } .pill.urgent { background:#fef3c7; color:var(--amber); } .pill.ok { background:#d1fae5; color:var(--green); }
        input, select, textarea { font:inherit; padding:8px; border:1px solid var(--line); border-radius:8px; width:100%; }
        label { display:block; margin:8px 0 4px; font-weight:600; font-size:13px; }
        button, .btn { font:inherit; background:var(--brand); color:#fff; border:0; border-radius:8px; padding:9px 14px; cursor:pointer; display:inline-block; }
        button.secondary, .btn.secondary { background:#fff; color:var(--ink); border:1px solid var(--line); }
        button.danger { background:var(--red); }
        .row { display:flex; gap:10px; flex-wrap:wrap; align-items:end; } .row > * { flex:1; min-width:140px; }
        .flash { background:#ecfdf5; border:1px solid #a7f3d0; padding:10px 14px; border-radius:8px; margin-bottom:14px; }
        .error { background:#fef2f2; border:1px solid #fecaca; padding:10px 14px; border-radius:8px; margin-bottom:14px; }
        .thread { max-height:320px; overflow:auto; }
        .msg { padding:6px 10px; border-radius:10px; margin:6px 0; max-width:85%; }
        .msg.inbound { background:#f3f4f6; } .msg.outbound { background:#dbeafe; margin-left:auto; }
        .big-btn { display:block; width:100%; padding:14px; font-size:17px; margin:8px 0; text-align:center; }
        @media (max-width:640px) { main { padding:10px; } .hide-sm { display:none; } }
    </style>
</head>
<body>
@auth
<header>
    <strong>Axiom Maintenance</strong>
    @if(auth()->user()->isAdmin())
        <a href="{{ route('admin.dashboard') }}">Dashboard</a>
        <a href="{{ route('admin.technicians') }}">People</a>
    @endif
    @if(auth()->user()->hasRole('admin', 'scheduler'))
        <a href="{{ route('schedule') }}">Schedule</a>
        <a href="{{ route('map') }}">Map</a>
        <a href="{{ route('queue') }}">Queue</a>
    @endif
    @if(auth()->user()->technician)
        <a href="{{ route('tech.today') }}">My day</a>
    @endif
    <form method="post" action="{{ route('logout') }}" style="margin:0">@csrf<button class="secondary">Sign out</button></form>
</header>
@endauth
<main class="@hasSection('wide') wide @endif">
    @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
    @yield('content')
</main>
@stack('scripts')
</body>
</html>
