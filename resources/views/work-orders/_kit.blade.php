<div class="card">
    <h3 style="margin-top:0">Supplies &amp; tools to bring</h3>
    @if($missing ?? false)<p class="error">Not on {{ isset($forTech) ? 'your' : 'the tech\'s' }} truck: {{ implode(', ', $missing) }}</p>@endif
    <div class="row" style="align-items:start">
        <div>
            <b>Anticipated supplies</b>
            <ul style="margin:4px 0 0;padding-left:18px">
                @forelse($kit['supplies'] as $s)
                    <li>{{ $s['name'] }}@if($s['qty'] > 1) × {{ $s['qty'] }}@endif <span class="muted">· {{ $s['from'] }}@if($s['on_truck'] === true) · on truck @elseif($s['on_truck'] === false) · <b style="color:var(--red)">not on truck</b>@endif</span></li>
                @empty
                    <li class="muted">Nothing specific. Standard truck stock.</li>
                @endforelse
            </ul>
        </div>
        <div>
            <b>Tools</b>
            <ul style="margin:4px 0 0;padding-left:18px">@foreach($kit['tools'] as $t)<li>{{ $t }}</li>@endforeach</ul>
        </div>
    </div>
</div>
