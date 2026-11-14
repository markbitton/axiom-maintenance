<div class="card receipt-drop" id="receipt-drop">
    <div class="row" style="align-items:center">
        <div><h3 style="margin:0">Receipts &amp; part costs</h3><div class="muted hide-sm">On a computer you can drag a receipt onto this box.</div></div>
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

<form method="post" action="{{ route('receipts.store') }}" enctype="multipart/form-data" id="receipt-drop-form" hidden>
    @csrf<input type="hidden" name="work_orders[]" value="{{ $wo->id }}"><input type="file" name="photo">
</form>
<style>
    .receipt-drop.over { outline:2px dashed #f59e0b; outline-offset:-2px; background:#fffbeb; }
</style>
<script>
(function () {
    const box = document.getElementById('receipt-drop'), form = document.getElementById('receipt-drop-form');
    const hasFile = e => [...(e.dataTransfer?.types || [])].includes('Files');
    ['dragenter', 'dragover'].forEach(t => box.addEventListener(t, e => { if (hasFile(e)) { e.preventDefault(); box.classList.add('over'); } }));
    box.addEventListener('dragleave', e => { if (!box.contains(e.relatedTarget)) box.classList.remove('over'); });
    box.addEventListener('drop', e => {
        const f = e.dataTransfer?.files[0];
        if (!f) return;
        e.preventDefault(); box.classList.remove('over');
        if (!(f.type.startsWith('image/') || f.type === 'application/pdf')) return;
        const dt = new DataTransfer(); dt.items.add(f); form.photo.files = dt.files;
        box.querySelector('h3').textContent = 'Uploading receipt…';
        form.submit(); // opens the receipt to add more jobs and match the items
    });
})();
</script>
