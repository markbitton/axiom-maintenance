@extends('layouts.app')
@section('title', 'Route map')
@section('wide', true)
@section('content')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<style>
    .sched-nav { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
    .sched-nav h2 { margin:0 auto 0 6px; font-size:20px; }
    .sched-nav .seg { display:flex; gap:6px; }
    .sched-nav .btn { padding:7px 12px; }
    .sched-nav input[type=date] { width:auto; padding:6px 8px; }
    .map-wrap { display:flex; gap:14px; align-items:stretch; }
    #map { flex:1; min-height:620px; border-radius:10px; border:1px solid var(--line); }
    .routes { width:320px; flex-shrink:0; max-height:620px; overflow:auto; }
    .route { border-left:4px solid; padding:4px 0 4px 10px; margin-bottom:14px; }
    .route h4 { margin:0; display:flex; justify-content:space-between; align-items:center; gap:6px; }
    .route h4 label { font-weight:400; font-size:12px; color:var(--muted); display:flex; gap:4px; align-items:center; margin:0; }
    .route h4 input { width:auto; }
    .route ol { margin:6px 0 0; padding-left:20px; font-size:13px; }
    .route li { margin:3px 0; cursor:pointer; }
    .route li.done { color:var(--muted); } .route li.done::after { content:' ✓ done'; color:var(--green); }
    .pin { width:26px; height:26px; border-radius:50%; color:#fff; font:700 12px/22px system-ui; text-align:center; border:2px solid #fff; box-shadow:0 1px 3px rgba(0,0,0,.4); }
    .pin.home { border-radius:6px; font-size:13px; }
    .pin.wait { background:#6b7280; width:16px; height:16px; line-height:12px; font-size:0; }
    @media (max-width:900px) { .map-wrap { flex-direction:column; } .routes { width:100%; max-height:none; } #map { min-height:420px; } }
</style>

@include('schedule._nav', ['page' => 'map', 'view' => 'day', 'title' => 'Routes · '.$date->format('l, M j')])

<div class="map-wrap">
    <div id="map"></div>
    <div class="routes card">
        @foreach($routes as $r)
            <div class="route" style="border-color:{{ $r['color'] }}">
                <h4>{{ $r['tech']->name }} <label><input type="checkbox" checked data-toggle="{{ $loop->index }}"> show</label></h4>
                <div class="muted">{{ $r['stops']->count() }} {{ \Illuminate\Support\Str::plural('stop', $r['stops']->count()) }} · about {{ $r['km'] }} km, {{ $r['driveMinutes'] }} min driving</div>
                <ol>
                    @foreach($r['stops'] as $s)
                        <li class="{{ in_array($s->status, ['completed', 'closed'], true) ? 'done' : '' }}" data-route="{{ $loop->parent->index }}" data-stop="{{ $loop->index }}">
                            {{ $s->scheduled_start->setTimezone($tz)->format('g:i') }} · {{ $s->tradeLabel() }} · {{ $s->property?->name }} {{ $s->unit?->name }}
                        </li>
                    @endforeach
                </ol>
                @if($r['stops']->isEmpty())<p class="muted" style="margin:4px 0 0">No stops this day.</p>@endif
            </div>
        @endforeach
        @if($waiting->isNotEmpty())
            <div class="route" style="border-color:#6b7280">
                <h4>Not booked yet <label><input type="checkbox" checked data-toggle="waiting"> show</label></h4>
                <div class="muted">{{ $waiting->count() }} open {{ \Illuminate\Support\Str::plural('request', $waiting->count()) }} (gray dots)</div>
            </div>
        @endif
        <p class="muted">Lines are straight between stops, in the order each tech should go. Drive times are estimates until a Google Maps key is added.</p>
    </div>
</div>

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
(() => {
    const routes = @json($points), waiting = @json($waiting);
    const map = L.map('map', { scrollWheelZoom: true });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);

    const esc = (s) => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const pin = (html, color, cls = '') => L.divIcon({ className: '', iconSize: [26, 26], iconAnchor: [13, 13],
        html: `<div class="pin ${cls}" style="background:${color}">${html}</div>` });
    const bounds = [], layers = {}, markers = {};

    routes.forEach((r, i) => {
        const g = L.layerGroup().addTo(map), line = [];
        markers[i] = [];
        if (r.start) {
            L.marker(r.start, { icon: pin('⌂', r.color, 'home') }).bindPopup(`<b>${esc(r.name)}</b><br>Start of day`).addTo(g);
            line.push(r.start); bounds.push(r.start);
        }
        r.stops.forEach(s => {
            const done = s.status === 'completed' || s.status === 'closed';
            const m = L.marker([s.lat, s.lng], { icon: pin(s.n, r.color), opacity: done ? 0.55 : 1 })
                .bindPopup(`<b>${s.n}. ${esc(s.time)}</b> · ${esc(r.name)}<br>${esc(s.title)}<br><a href="${s.url}">${esc(s.ref)}</a>${done ? ' · done' : ''}`).addTo(g);
            markers[i].push(m); line.push([s.lat, s.lng]); bounds.push([s.lat, s.lng]);
        });
        if (line.length > 1) L.polyline(line, { color: r.color, weight: 4, opacity: 0.75 }).addTo(g);
        layers[i] = g;
    });

    const wg = L.layerGroup().addTo(map);
    waiting.forEach(w => {
        L.marker([w.lat, w.lng], { icon: L.divIcon({ className: '', iconSize: [16, 16], html: '<div class="pin wait"></div>' }), zIndexOffset: -1000 })
            .bindPopup(`<b>Not booked</b><br>${esc(w.title)}<br><a href="${w.url}">${esc(w.ref)}</a>`).addTo(wg);
        bounds.push([w.lat, w.lng]);
    });
    layers.waiting = wg;

    if (bounds.length) map.fitBounds(bounds, { padding: [30, 30] }); else map.setView([43.615, -116.35], 11);

    document.querySelectorAll('[data-toggle]').forEach(cb => cb.addEventListener('change', () => {
        const g = layers[cb.dataset.toggle]; cb.checked ? g.addTo(map) : map.removeLayer(g);
    }));
    document.querySelectorAll('li[data-route]').forEach(li => li.addEventListener('click', () => {
        const m = (markers[li.dataset.route] || [])[li.dataset.stop];
        if (m) { map.setView(m.getLatLng(), 14); m.openPopup(); }
    }));
})();
</script>
@endpush
@endsection
