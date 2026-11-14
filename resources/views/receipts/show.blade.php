@extends('layouts.app')
@section('title', 'Receipt')
@section('content')
@php
    $attached = $receipt->workOrders->pluck('id')->all();
    $options = function ($line) use ($receipt) {
        $html = '<option value="">Not for a job (shop stock)</option>';
        foreach ($receipt->workOrders as $wo) {
            $html .= '<optgroup label="'.e($wo->reference.' · '.$wo->tradeLabel().' · '.($wo->property?->name ?? '')).'">';
            foreach ($wo->parts as $p) {
                $sel = $line && $line->work_order_part_id === $p->id ? ' selected' : '';
                $html .= '<option value="wop:'.$p->id.'"'.$sel.'>'.e($p->part_name).($p->unit_cost !== null ? ' ($'.number_format($p->unit_cost, 2).')' : '').'</option>';
            }
            $sel = $line && ! $line->work_order_part_id && $line->work_order_id === $wo->id ? ' selected' : '';
            $html .= '<option value="wo:'.$wo->id.'"'.$sel.'>+ Add as a new part on '.e($wo->reference).'</option></optgroup>';
        }

        return $html;
    };
@endphp
<style>
    .rc-wrap { display:grid; grid-template-columns:minmax(0,320px) minmax(0,1fr); gap:16px; align-items:start; }
    .rc-photo img { width:100%; border-radius:8px; border:1px solid var(--line); }
    .rc-lines td { vertical-align:middle; padding:6px 4px; }
    .rc-lines input, .rc-lines select { padding:6px; }
    .rc-lines .matched { border-color:#86efac; background:#f0fdf4; }
    .job-pick { display:flex; gap:8px; align-items:flex-start; font-weight:400; margin:4px 0; }
    .job-pick input { width:auto; margin-top:3px; }
    @media (max-width:800px) { .rc-wrap { grid-template-columns:1fr; } .rc-lines thead { display:none; } .rc-lines tr { display:grid; grid-template-columns:1fr 70px 90px; gap:4px; border-bottom:1px solid var(--line); padding:6px 0; } .rc-lines td { border:0; padding:0; } .rc-lines td.assign { grid-column:1 / -1; } }
</style>
<form method="post" action="{{ route('receipts.update', $receipt) }}">
    @csrf @method('PUT')
    <div class="rc-wrap">
        <div class="card rc-photo">
            @if($receipt->photo_path)
                @if(str_starts_with((string) $receipt->photo_type, 'image/'))
                    <a href="{{ route('receipts.photo', $receipt) }}" target="_blank"><img src="{{ route('receipts.photo', $receipt) }}" alt="Receipt photo"></a>
                @else
                    <a class="btn secondary" href="{{ route('receipts.photo', $receipt) }}" target="_blank">Open receipt file</a>
                @endif
            @else
                <p class="muted">No photo attached.</p>
            @endif
            <label for="vendor">Store</label>
            <input id="vendor" name="vendor" list="vendors" value="{{ $receipt->vendor?->name }}" autocomplete="off" placeholder="e.g. Home Depot">
            <datalist id="vendors">@foreach($vendors as $v)<option value="{{ $v }}">@endforeach</datalist>
            <div class="row">
                <div><label>Date</label><input type="date" name="purchased_on" value="{{ $receipt->purchased_on?->toDateString() }}"></div>
                <div><label>Total ($)</label><input type="number" step="0.01" min="0" name="total" value="{{ $receipt->total }}"></div>
            </div>
            <label>Jobs on this receipt</label>
            @foreach($jobs as $j)
                <label class="job-pick"><input type="checkbox" name="work_orders[]" value="{{ $j->id }}" @checked(in_array($j->id, $attached))>
                    <span>{{ $j->reference }} · {{ $j->tradeLabel() }} · {{ $j->property?->name }} {{ $j->unit?->name }}</span></label>
            @endforeach
            <p class="muted">Tick another job and Save to split this receipt across it.</p>
        </div>

        <div class="card">
            <h3 style="margin-top:0">Items {{ $receipt->read_by === 'ai' ? '(read from the photo)' : '' }}</h3>
            <p class="muted" style="margin-top:0">Match each item to the part it was for. Green = we found the match. Saving puts the price on that job's part and records it in the parts price history for this store.</p>
            <table class="rc-lines">
                <thead><tr><th>Item</th><th style="width:70px">Qty</th><th style="width:100px">Each ($)</th><th>For job / part</th></tr></thead>
                <tbody id="rc-body">
                @foreach($receipt->lines as $i => $line)
                    <tr>
                        <td><input type="hidden" name="lines[{{ $i }}][id]" value="{{ $line->id }}"><input name="lines[{{ $i }}][description]" value="{{ $line->description }}"></td>
                        <td><input type="number" step="0.01" min="0" name="lines[{{ $i }}][quantity]" value="{{ rtrim(rtrim(number_format($line->quantity, 2), '0'), '.') }}"></td>
                        <td><input type="number" step="0.01" min="0" name="lines[{{ $i }}][unit_price]" value="{{ $line->unit_price > 0 ? number_format($line->unit_price, 2, '.', '') : '' }}" placeholder="0.00"></td>
                        <td class="assign"><select name="lines[{{ $i }}][assign]" class="{{ $line->work_order_part_id ? 'matched' : '' }}">{!! $options($line) !!}</select></td>
                    </tr>
                @endforeach
                @for($k = $receipt->lines->count(); $k < $receipt->lines->count() + 3; $k++)
                    <tr>
                        <td><input name="lines[{{ $k }}][description]" placeholder="Another item"></td>
                        <td><input type="number" step="0.01" min="0" name="lines[{{ $k }}][quantity]" value="1"></td>
                        <td><input type="number" step="0.01" min="0" name="lines[{{ $k }}][unit_price]" placeholder="0.00"></td>
                        <td class="assign"><select name="lines[{{ $k }}][assign]">{!! $options(null) !!}</select></td>
                    </tr>
                @endfor
                </tbody>
            </table>
            <p class="muted">Items total: $<span id="rc-sum">0.00</span>{{ $receipt->total ? ' · receipt total $'.number_format((float) $receipt->total, 2).' (includes tax)' : '' }}</p>
            <button class="big-btn">Save receipt</button>
        </div>
    </div>
</form>
@endsection
@push('scripts')
<script>
(() => {
    const sum = () => {
        let t = 0;
        document.querySelectorAll('#rc-body tr').forEach(r => {
            const q = parseFloat(r.querySelector('[name$="[quantity]"]').value) || 0, p = parseFloat(r.querySelector('[name$="[unit_price]"]').value) || 0;
            t += q * p;
        });
        document.getElementById('rc-sum').textContent = t.toFixed(2);
    };
    document.getElementById('rc-body').addEventListener('input', sum); sum();
})();
</script>
@endpush
