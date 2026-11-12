@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<div class="grid">
    <div class="card stat"><span class="muted">Open jobs</span><b>{{ $open }}</b></div>
    <div class="card stat"><span class="muted">Needs a person</span><b><a href="{{ route('queue') }}">{{ $review }}</a></b></div>
    <div class="card stat"><span class="muted">Open emergencies</span><b style="color:var(--red)">{{ $emergencies }}</b></div>
    <div class="card stat"><span class="muted">Visits today</span><b>{{ $today }}</b></div>
    <div class="card stat"><span class="muted">Booked with no one touching it (30 days)</span><b>{{ $hands_off_rate === null ? '–' : $hands_off_rate.'%' }}</b></div>
    <div class="card stat"><span class="muted">Average tenant rating</span><b>{{ $avg_rating ?: '–' }}</b></div>
</div>

@if($demo)
<div class="card row" style="align-items:center">
    <div><b>Beta test data.</b> <span class="muted">The work orders here are made up. Reset them any time to get a fresh set dated around today.</span></div>
    <form method="post" action="{{ route('admin.demo.reset') }}" style="flex:0" onsubmit="return confirm('Delete every work order and make a fresh demo set?')">@csrf<button class="secondary">Reset demo data</button></form>
</div>
@endif

<div class="card">
    <h3 style="margin-top:0">Service locations</h3>
    <p class="muted">Only active locations pull work orders from Rent Manager.</p>
    <table>
        <tr><th>Rent Manager ID</th><th>Location</th><th>Status</th><th>Last sync</th><th></th></tr>
        @foreach($locations as $loc)
        <tr>
            <td>{{ $loc->rm_location_id }}</td><td>{{ $loc->name }}</td>
            <td>@if($loc->enabled)<span class="pill ok">Active</span>@else<span class="pill">Off</span>@endif</td>
            <td class="muted">{{ $loc->last_issue_sync_at?->diffForHumans() ?? 'never' }}</td>
            <td><form method="post" action="{{ route('admin.locations.toggle', $loc) }}">@csrf<button class="secondary">{{ $loc->enabled ? 'Turn off' : 'Turn on' }}</button></form></td>
        </tr>
        @endforeach
    </table>
</div>

<div class="card">
    <h3 style="margin-top:0">Requests by trade (30 days)</h3>
    @forelse($byTrade as $trade => $n)<span class="pill" style="margin:2px">{{ ucfirst($trade ?: 'untriaged') }}: {{ $n }}</span>@empty<p class="muted">No requests yet.</p>@endforelse
</div>

<div class="card">
    <h3 style="margin-top:0">Latest requests</h3>
    <table>
        <tr><th>Ref</th><th>What</th><th class="hide-sm">Tech</th><th>Status</th></tr>
        @foreach($recent as $wo)
        <tr>
            <td><a href="{{ route('queue.show', $wo) }}">{{ $wo->reference }}</a></td>
            <td>@if($wo->urgency !== 'routine')<span class="pill {{ $wo->urgency }}">{{ $wo->urgency }}</span> @endif{{ \Illuminate\Support\Str::limit($wo->description, 70) }}</td>
            <td class="hide-sm">{{ $wo->technician?->name ?? '–' }}</td>
            <td>{{ str_replace('_', ' ', $wo->status) }}</td>
        </tr>
        @endforeach
    </table>
</div>
@endsection
