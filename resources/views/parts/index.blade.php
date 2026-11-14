@extends('layouts.app')
@section('title', 'Parts')
@section('content')
<div class="row" style="align-items:center;margin-bottom:10px">
    <h2 style="margin:0">Parts &amp; prices</h2>
    <form method="get" style="flex:0;min-width:260px"><input name="q" value="{{ $q }}" placeholder="Search parts"></form>
</div>
<p class="muted">Prices come from receipts techs attach to jobs. "Best price" is the cheapest store in the last 12 months. Click a part to see every purchase and edit its default cost.</p>
<div class="card" style="overflow-x:auto">
    <table>
        <tr><th>Part</th><th>Trade</th><th style="text-align:right">Default cost</th><th>Best price (12 mo)</th><th style="text-align:right">Last paid</th><th style="text-align:right">Buys</th><th style="text-align:right">On trucks</th></tr>
        @forelse($parts as $p)
            @php $pp = $prices->get($p->id, collect()); $low = $pp->sortBy('unit_cost')->first(); $last = $pp->sortByDesc('purchased_on')->first(); @endphp
            <tr>
                <td><a href="{{ route('parts.show', $p) }}">{{ $p->name }}</a><div class="muted">{{ $p->sku }}</div></td>
                <td>{{ $p->trade }}</td>
                <td style="text-align:right">${{ number_format((float) $p->unit_cost, 2) }}</td>
                <td>@if($low)<b>${{ number_format($low->unit_cost, 2) }}</b> <span class="muted">at {{ $low->vendor?->name ?? 'unknown' }}</span>@else<span class="muted">—</span>@endif</td>
                <td style="text-align:right">@if($last)${{ number_format($last->unit_cost, 2) }}<div class="muted">{{ $last->vendor?->name }}</div>@else<span class="muted">—</span>@endif</td>
                <td style="text-align:right">{{ $pp->count() ?: '' }}</td>
                <td style="text-align:right">{{ (int) ($stock[$p->id] ?? 0) ?: '' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No parts found.</td></tr>
        @endforelse
    </table>
</div>
<form class="card" method="post" action="{{ route('parts.store') }}">
    @csrf
    <h3 style="margin-top:0">Add a part</h3>
    <div class="row">
        <div style="flex:2"><label>Name</label><input name="name" required></div>
        <div><label>Trade</label><select name="trade"><option value="">Any</option>@foreach(['plumbing','electrical','hvac','appliance','locks','pest','general'] as $t)<option>{{ $t }}</option>@endforeach</select></div>
        <div><label>Default cost ($)</label><input type="number" step="0.01" min="0" name="unit_cost"></div>
        <div style="flex:0"><button>Add</button></div>
    </div>
</form>
@endsection
