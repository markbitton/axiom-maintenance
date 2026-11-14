<div class="card">
    <div class="row" style="align-items:center">
        <h3 style="margin:0">Receipts &amp; part costs</h3>
        <div style="flex:0;white-space:nowrap"><a class="btn" href="{{ route('receipts.create', ['wo' => $wo->id]) }}">📷 Add receipt</a></div>
    </div>
    @if($wo->parts->where('used', true)->isNotEmpty())
        <table style="margin-top:8px">
            <tr><th>Part used</th><th style="text-align:right">Qty</th><th style="text-align:right">Paid each</th><th>Store</th></tr>
            @foreach($wo->parts->where('used', true) as $p)
                <tr><td>{{ $p->part_name }}</td><td style="text-align:right">{{ $p->quantity }}</td>
                    <td style="text-align:right">@if($p->unit_cost !== null)${{ number_format($p->unit_cost, 2) }}@else<span class="muted" title="No receipt yet; invoices use the parts list cost">${{ number_format((float) ($p->part?->unit_cost ?? 0), 2) }} est.</span>@endif</td>
                    <td>{{ $p->vendor?->name }}</td></tr>
            @endforeach
        </table>
    @endif
    @forelse($wo->receipts as $r)
        <div style="margin-top:8px"><a href="{{ route('receipts.show', $r) }}">{{ $r->vendor?->name ?? 'Receipt' }} · {{ $r->purchased_on?->format('M j') }}{{ $r->total ? ' · $'.number_format((float) $r->total, 2) : '' }}</a>
            @if($r->workOrders->count() > 1)<span class="muted">· split across {{ $r->workOrders->count() }} jobs</span>@endif</div>
    @empty
        <p class="muted" style="margin-bottom:0">No receipts yet. Snap one to record what parts cost and where they came from.</p>
    @endforelse
</div>
