@extends('layouts.app')
@section('title', $part->name)
@section('content')
<p><a href="{{ route('parts') }}">&larr; Parts</a></p>
<div class="card">
    <h2 style="margin-top:0">{{ $part->name }}</h2>
    @if($byStore->isNotEmpty())
        @php $best = $byStore->keys()->first(); @endphp
        <p class="flash" style="margin:0 0 10px">Cheapest at <b>{{ $best }}</b>: ${{ number_format($byStore->first()['low'], 2) }} (last bought there {{ $byStore->first()['when']->format('M j, Y') }}).</p>
        <table>
            <tr><th>Store</th><th style="text-align:right">Lowest</th><th style="text-align:right">Average</th><th style="text-align:right">Last paid</th><th style="text-align:right">Buys</th></tr>
            @foreach($byStore as $store => $s)
                <tr><td>{{ $store }}</td><td style="text-align:right">${{ number_format($s['low'], 2) }}</td><td style="text-align:right">${{ number_format($s['avg'], 2) }}</td>
                    <td style="text-align:right">${{ number_format($s['last'], 2) }} <span class="muted">{{ $s['when']->format('M j') }}</span></td><td style="text-align:right">{{ $s['count'] }}</td></tr>
            @endforeach
        </table>
    @else
        <p class="muted">No purchases recorded yet. Prices appear here when a tech attaches a receipt with this part.</p>
    @endif
</div>
<div class="row" style="align-items:start">
    <form class="card" method="post" action="{{ route('parts.update', $part) }}">
        @csrf @method('PUT')
        <h3 style="margin-top:0">Details</h3>
        <label>Name</label><input name="name" value="{{ $part->name }}" required>
        <div class="row">
            <div><label>Trade</label><select name="trade"><option value="">Any</option>@foreach(['plumbing','electrical','hvac','appliance','locks','pest','general'] as $t)<option @selected($part->trade === $t)>{{ $t }}</option>@endforeach</select></div>
            <div><label>Default cost ($)</label><input type="number" step="0.01" min="0" name="unit_cost" value="{{ number_format((float) $part->unit_cost, 2, '.', '') }}"></div>
        </div>
        <label>Store item number</label><input name="vendor_sku" value="{{ $part->vendor_sku }}">
        <label>Words that mean this part (comma separated)</label><input name="keywords" value="{{ implode(', ', $part->keywords ?? []) }}">
        <p class="muted">The default cost is used on invoices when a job's receipt didn't give a price. It updates to the latest price paid.</p>
        <button>Save</button>
    </form>
    <div class="card">
        <h3 style="margin-top:0">Purchase history</h3>
        @forelse($history as $h)
            <div>{{ $h->purchased_on->format('M j, Y') }} · {{ $h->vendor?->name ?? 'Unknown store' }} · <b>${{ number_format($h->unit_cost, 2) }}</b></div>
        @empty
            <p class="muted">None yet.</p>
        @endforelse
        @if($stock->isNotEmpty())
            <h3>On hand</h3>
            @foreach($stock as $s)<div>{{ $s->stockLocation?->name }}: {{ $s->quantity }}</div>@endforeach
        @endif
    </div>
</div>
@endsection
