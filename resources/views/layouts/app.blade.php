<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f2a4a">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/img/ams-logo.svg" type="image/svg+xml">
    <title>@yield('title', 'Axiom Maintenance Services')</title>
    <style>
        :root { --bg:#f6f7f9; --card:#fff; --ink:#111827; --muted:#6b7280; --line:#e5e7eb; --brand:#1d4ed8; --red:#b91c1c; --amber:#b45309; --green:#047857; }
        * { box-sizing: border-box; }
        body { margin:0; font:15px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; background:var(--bg); color:var(--ink); }
        a { color:var(--brand); text-decoration:none; }
        header.top { background:linear-gradient(135deg,#0f2a4a 0%,#173d66 100%); color:#fff; box-shadow:0 2px 10px rgba(15,42,74,.25); position:sticky; top:0; z-index:40; }
        header.top::after { content:''; display:block; height:3px; background:linear-gradient(90deg,#f59e0b,#fbbf24 60%,transparent); }
        .top-inner { display:flex; align-items:center; gap:18px; padding:10px 20px; }
        .brand { display:flex; align-items:center; gap:11px; color:#fff; margin-right:auto; }
        .brand img { border-radius:10px; box-shadow:0 0 0 2px rgba(255,255,255,.15); }
        .brand b { display:block; font-size:16.5px; letter-spacing:.2px; line-height:1.2; }
        .brand small { display:block; color:#93c5fd; font-size:11.5px; letter-spacing:.4px; }
        .menu { display:flex; align-items:center; gap:4px; }
        .menu a, .menu-more { color:#dbeafe; padding:8px 12px; border-radius:8px; font-weight:500; font-size:14.5px; background:none; border:0; cursor:pointer; }
        .menu a:hover, .menu-more:hover { background:rgba(255,255,255,.1); color:#fff; }
        .menu a.on, .menu-more.on { background:rgba(255,255,255,.16); color:#fff; box-shadow:inset 0 -2px 0 #f59e0b; }
        .menu a.cta { background:#f59e0b; color:#1f2937; font-weight:700; margin-left:6px; }
        .menu a.cta:hover { background:#fbbf24; }
        .menu-group { position:relative; }
        .menu-drop { display:none; position:absolute; right:0; top:100%; background:#fff; border-radius:10px; box-shadow:0 10px 30px rgba(15,42,74,.2); padding:6px; min-width:180px; }
        .menu-group:hover .menu-drop, .menu-group:focus-within .menu-drop { display:block; }
        .menu-drop a { display:block; color:var(--ink); }
        .menu-drop a:hover { background:#f1f5f9; color:var(--ink); }
        .menu-drop a.on { background:#eff6ff; color:#1d4ed8; box-shadow:none; }
        .menu-user { display:flex; align-items:center; gap:8px; margin-left:10px; padding-left:12px; border-left:1px solid rgba(255,255,255,.18); }
        .menu-user form { margin:0; }
        .avatar { width:32px; height:32px; border-radius:50%; background:#f59e0b; color:#1f2937; font-weight:800; font-size:13px; display:grid; place-items:center; }
        .signout { background:transparent; border:1px solid rgba(255,255,255,.3); color:#dbeafe; padding:6px 11px; font-size:13px; }
        .signout:hover { background:rgba(255,255,255,.1); color:#fff; }
        .menu-toggle { display:none; background:transparent; border:0; padding:8px; }
        .menu-toggle span { display:block; width:22px; height:2px; background:#fff; margin:5px 0; border-radius:2px; }
        @media (max-width:980px) {
            .brand small { display:none; }
            .menu-toggle { display:block; }
            .menu { display:none; position:absolute; left:0; right:0; top:100%; background:#0f2a4a; flex-direction:column; align-items:stretch; padding:8px 14px 14px; gap:2px; box-shadow:0 10px 20px rgba(0,0,0,.25); }
            body.menu-open .menu { display:flex; }
            .menu-drop { display:block; position:static; background:none; box-shadow:none; padding:0 0 0 12px; }
            .menu-drop a { color:#dbeafe; } .menu-drop a:hover { background:rgba(255,255,255,.1); color:#fff; }
            .menu-more { display:none; }
            .menu a.cta { margin:6px 0 0; text-align:center; }
            .menu-user { margin:8px 0 0; padding:8px 0 0; border-left:0; border-top:1px solid rgba(255,255,255,.18); }
        }
        @media (max-width:420px) { .brand b { font-size:14px; } }
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
        @media print { header.top, .no-print { display:none !important; } body { background:#fff; } .card { border:0; } }
        .big-btn { display:block; width:100%; padding:14px; font-size:17px; margin:8px 0; text-align:center; }
        @media (max-width:640px) { main { padding:10px; } .hide-sm { display:none; } }
    </style>
</head>
<body>
@auth
@php
    $u = auth()->user();
    $link = fn ($route, $label, $match = null) => '<a href="'.route($route).'" class="'.(request()->routeIs($match ?? $route) ? 'on' : '').'">'.e($label).'</a>';
@endphp
<header class="top">
    <div class="top-inner">
        <a href="/" class="brand">
            <img src="/img/ams-logo.svg" alt="AMS" width="40" height="40">
            <span><b>Axiom Maintenance Services</b><small>Scheduling &amp; field service</small></span>
        </a>
        <button type="button" class="menu-toggle" aria-label="Menu" aria-expanded="false" onclick="const o=document.body.classList.toggle('menu-open');this.setAttribute('aria-expanded',o)">
            <span></span><span></span><span></span>
        </button>
        <nav class="menu">
            @if($u->hasRole('admin', 'scheduler'))
                {!! $link('schedule', 'Schedule') !!}
                {!! $link('map', 'Map') !!}
                {!! $link('queue', 'Queue', 'queue*') !!}
                {!! $link('invoices', 'Invoices', 'invoices*') !!}
            @endif
            @if($u->technician)
                {!! $link('tech.today', 'My day', 'tech.*') !!}
            @endif
            @if($u->isAdmin())
                <div class="menu-group">
                    <button type="button" class="menu-more {{ request()->routeIs('admin.*') ? 'on' : '' }}">Admin ▾</button>
                    <div class="menu-drop">
                        {!! $link('admin.dashboard', 'Dashboard') !!}
                        {!! $link('admin.technicians', 'People', 'admin.technicians*') !!}
                        {!! $link('admin.rates', 'Rates & fees') !!}
                        {!! $link('admin.efficiency', 'Efficiency') !!}
                    </div>
                </div>
            @endif
            @if($u->hasRole('admin', 'scheduler'))
                <a href="{{ route('work-orders.create') }}" class="cta">+ New work order</a>
            @endif
            <div class="menu-user">
                <span class="avatar" title="{{ $u->name }}">{{ \Illuminate\Support\Str::of($u->name)->explode(' ')->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('') }}</span>
                <form method="post" action="{{ route('logout') }}">@csrf<button class="signout">Sign out</button></form>
            </div>
        </nav>
    </div>
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
