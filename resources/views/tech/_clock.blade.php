@php($tz = $tech->location->timezone ?? 'UTC')
<div class="card clock-card">
    @if(! $shift)
        <div class="row" style="align-items:center">
            <div><b>Off the clock</b><div class="muted">Clock in to start your day. Your phone's location is required.</div></div>
        </div>
        <form method="post" action="{{ route('tech.clock.in') }}" data-gps>@csrf<button class="big-btn">Clock in</button></form>
    @else
        <div class="row" style="align-items:center">
            <div>
                <div class="muted">On the clock since {{ $shift->started_at->setTimezone($tz)->format('g:i A') }}</div>
                <div class="timer" data-since="{{ $shift->started_at->toIso8601String() }}">0:00</div>
            </div>
            <div>
                @if($activity && $activity->type === 'break')
                    <div class="muted">On break (billable clock stopped)</div>
                @elseif($activity)
                    <div class="muted">{{ $activity->type === 'travel' ? 'Driving to' : 'Working on' }} <a href="{{ route('tech.show', $activity->work_order_id) }}">{{ $activity->workOrder?->reference }}</a></div>
                @else
                    <div class="muted">No job clock running</div>
                @endif
                @if($activity)<div class="timer {{ $activity->type }}" data-since="{{ $activity->started_at->toIso8601String() }}">0:00</div>@endif
            </div>
        </div>
        <div class="row">
            @if($activity && $activity->type === 'break')
                <form method="post" action="{{ route('tech.break.end') }}" data-gps>@csrf<button class="big-btn">End break{{ $activity->work_order_id ? ', resume job' : '' }}</button></form>
            @else
                <form method="post" action="{{ route('tech.break.start') }}" data-gps>@csrf<button class="big-btn secondary">Start break</button></form>
            @endif
            <form method="post" action="{{ route('tech.clock.out') }}" data-gps>@csrf<button class="big-btn secondary">Clock out</button></form>
        </div>
    @endif
</div>
<style>
    .clock-card .timer { font:700 26px/1.2 ui-monospace,Menlo,monospace; }
    .clock-card .timer.travel { color:#b45309; } .clock-card .timer.work { color:var(--green); } .clock-card .timer.break { color:var(--muted); }
</style>
<script>
(() => {
    const fmt = (s) => { const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60); return h + ':' + String(m).padStart(2, '0'); };
    const tick = () => document.querySelectorAll('.timer[data-since]').forEach(t => t.textContent = fmt(Math.max(0, (Date.now() - Date.parse(t.dataset.since)) / 1000)));
    tick(); setInterval(tick, 15000);
})();
</script>
