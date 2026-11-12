@extends('layouts.app')
@section('title', 'Schedule')
@section('wide', true)
@section('content')
@php
    $pxPerMin = 1.1;
    $statusLabel = ['scheduled' => 'Booked', 'en_route' => 'On the way', 'in_progress' => 'Working', 'completed' => 'Done', 'closed' => 'Closed'];
    $local = fn ($t) => $t->copy()->setTimezone($tz);
@endphp
<style>
    .sched-nav { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
    .sched-nav h2 { margin:0 auto 0 6px; font-size:20px; }
    .sched-nav .seg { display:flex; gap:6px; }
    .sched-nav .btn { padding:7px 12px; }
    .sched-nav input[type=date] { width:auto; padding:6px 8px; }
    .sched-wrap { display:flex; gap:14px; align-items:flex-start; }
    .sched-main { flex:1; min-width:0; }
    .sched-side { width:260px; flex-shrink:0; }
    .day-grid { display:flex; overflow-x:auto; background:var(--card); border:1px solid var(--line); border-radius:10px; }
    .times { width:54px; flex-shrink:0; border-right:1px solid var(--line); }
    .col { flex:1; min-width:170px; border-right:1px solid var(--line); }
    .col:last-child { border-right:0; }
    .col-head, .times .col-head { height:46px; border-bottom:1px solid var(--line); padding:6px 8px; font-weight:600; font-size:14px; position:sticky; top:0; background:var(--card); z-index:2; }
    .col-head small { display:block; font-weight:400; color:var(--muted); font-size:12px; }
    .col-head i { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; }
    .lane { position:relative; }
    .lane.over { background:#eff6ff; }
    .hour { position:absolute; left:0; right:0; border-top:1px solid #f0f1f3; font-size:11px; color:var(--muted); padding:0 4px; }
    .hour.half { border-top-style:dashed; }
    .job { position:absolute; left:4px; right:4px; border-radius:7px; padding:4px 7px; font-size:12px; line-height:1.3; overflow:hidden; color:#fff; cursor:grab; box-shadow:0 1px 2px rgba(0,0,0,.15); }
    .job:hover { z-index:3; overflow:visible; min-height:fit-content; }
    .job b { font-size:12.5px; }
    .job .st { opacity:.9; }
    .job.done { opacity:.45; cursor:default; }
    .job.working { outline:2px solid #111827; }
    .job.emergency { background-image:repeating-linear-gradient(45deg, rgba(255,255,255,.18) 0 6px, transparent 6px 12px); }
    .job a { color:#fff; }
    .now-line { position:absolute; left:0; right:0; border-top:2px solid var(--red); z-index:4; pointer-events:none; }
    .card-job { border:1px solid var(--line); border-left:4px solid #9ca3af; border-radius:8px; padding:7px 9px; margin:6px 0; background:#fff; cursor:grab; font-size:13px; }
    .card-job.emergency { border-left-color:var(--red); } .card-job.urgent { border-left-color:var(--amber); }
    .week { width:100%; border-collapse:separate; border-spacing:0; background:var(--card); border:1px solid var(--line); border-radius:10px; overflow:hidden; }
    .week th, .week td { border-bottom:1px solid var(--line); border-right:1px solid var(--line); padding:6px; vertical-align:top; }
    .week td.cell { min-width:110px; height:90px; }
    .week td.cell.over { background:#eff6ff; }
    .week td.today, .week th.today { background:#f8fafc; }
    .chip { display:block; color:#fff; border-radius:6px; padding:2px 6px; margin:3px 0; font-size:12px; cursor:grab; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .chip.done { opacity:.45; cursor:default; }
    .legend { display:flex; gap:12px; flex-wrap:wrap; font-size:12px; color:var(--muted); margin-top:8px; }
    #toast { position:fixed; bottom:18px; left:50%; transform:translateX(-50%); background:#111827; color:#fff; padding:10px 16px; border-radius:8px; display:none; z-index:50; }
    @media (max-width:900px) { .sched-wrap { flex-direction:column; } .sched-side { width:100%; } }
</style>

@include('schedule._nav', ['page' => 'schedule', 'title' => $view === 'week'
    ? 'Week of '.$from->format('M j')
    : $date->format('l, M j')])

<div class="sched-wrap">
<div class="sched-main">
@if($view === 'day')
    @php $height = ($dayEnd - $dayStart) * 60 * $pxPerMin; $now = \Carbon\CarbonImmutable::now($tz); @endphp
    <div class="day-grid">
        <div class="times">
            <div class="col-head"></div>
            <div class="lane" style="height:{{ $height }}px">
                @for($h = $dayStart; $h < $dayEnd; $h++)
                    <div class="hour" style="top:{{ ($h - $dayStart) * 60 * $pxPerMin }}px;border:0">{{ \Carbon\Carbon::createFromTime($h)->format('g A') }}</div>
                @endfor
            </div>
        </div>
        @forelse($techs as $tech)
            @php
                $tj = $jobs->get($tech->id, collect());
                // Side-by-side lanes for overlapping visits: [job id => [lane, lanes in its cluster]]
                $lanes = []; $cluster = []; $clusterEnd = null; $laneEnds = [];
                $flush = function () use (&$cluster, &$lanes, &$laneEnds) {
                    foreach ($cluster as $id => $lane) { $lanes[$id] = [$lane, max(1, count($laneEnds))]; }
                    $cluster = []; $laneEnds = [];
                };
                foreach ($tj as $j) {
                    $js = $j->scheduled_start; $je = $j->scheduled_end ?? $js->copy()->addHours(2);
                    if ($clusterEnd && $js->gte($clusterEnd)) { $flush(); $clusterEnd = null; }
                    $lane = collect($laneEnds)->search(fn ($end) => $js->gte($end));
                    if ($lane === false) { $lane = count($laneEnds); }
                    $laneEnds[$lane] = $je; $cluster[$j->id] = $lane;
                    $clusterEnd = $clusterEnd && $clusterEnd->gt($je) ? $clusterEnd : $je;
                }
                $flush();
            @endphp
            <div class="col">
                <div class="col-head"><i style="background:{{ $colors[$tech->id] }}"></i>{{ $tech->name }}
                    <small>{{ $tj->count() }} {{ \Illuminate\Support\Str::plural('job', $tj->count()) }}@if($tech->on_call) · on call @endif</small></div>
                <div class="lane drop-day" data-tech="{{ $tech->id }}" data-tech-name="{{ $tech->name }}" style="height:{{ $height }}px">
                    @for($h = $dayStart; $h < $dayEnd; $h++)
                        <div class="hour" style="top:{{ ($h - $dayStart) * 60 * $pxPerMin }}px"></div>
                        <div class="hour half" style="top:{{ (($h - $dayStart) * 60 + 30) * $pxPerMin }}px"></div>
                    @endfor
                    @if($now->isSameDay($date) && $now->hour >= $dayStart && $now->hour < $dayEnd)
                        <div class="now-line" style="top:{{ (($now->hour - $dayStart) * 60 + $now->minute) * $pxPerMin }}px"></div>
                    @endif
                    @foreach($tj as $job)
                        @php
                            $s = $local($job->scheduled_start); $e = $job->scheduled_end ? $local($job->scheduled_end) : $s->copy()->addHours(2);
                            $top = max(0, (($s->hour - $dayStart) * 60 + $s->minute) * $pxPerMin);
                            $h = max(34, $s->diffInMinutes($e) * $pxPerMin - 3);
                            $done = in_array($job->status, ['completed', 'closed'], true);
                        @endphp
                        <div class="job {{ $done ? 'done' : '' }} {{ $job->status === 'in_progress' ? 'working' : '' }} {{ $job->urgency === 'emergency' ? 'emergency' : '' }}"
                             style="top:{{ $top }}px;height:{{ $h }}px;background:{{ $colors[$tech->id] }};left:calc({{ $lanes[$job->id][0] }} * (100% - 6px) / {{ $lanes[$job->id][1] }} + 3px);width:calc((100% - 6px) / {{ $lanes[$job->id][1] }} - 2px);right:auto"
                             @unless($done) draggable="true" data-id="{{ $job->id }}" data-ref="{{ $job->reference }}" @endunless
                             title="{{ $job->description }}">
                            <b><a href="{{ route('queue.show', $job) }}">{{ $s->format('g:i') }}–{{ $e->format('g:i') }}</a></b> · {{ $job->tradeLabel() }}
                            <div>{{ $job->property?->name }} {{ $job->unit?->name }}</div>
                            <div class="st">{{ $statusLabel[$job->status] ?? $job->status }}@if($job->urgency !== 'routine') · {{ $job->urgency }}@endif</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="col" style="padding:20px">No active techs yet. Add them under People.</div>
        @endforelse
    </div>
    <div class="legend"><span>Drag a job to another time or tech. Drag a job from the right to book it.</span><span>Faded = done</span><span>Outlined = being worked on</span><span>Striped = emergency</span></div>
@else
    @php
        $today = \Carbon\CarbonImmutable::now($tz);
        $weekend = $jobs->flatten()->contains(fn ($j) => $local($j->scheduled_start)->isWeekend());
        $days = collect(range(0, $weekend ? 6 : 4))->map(fn ($i) => $from->addDays($i));
    @endphp
    <div style="overflow-x:auto">
    <table class="week">
        <tr><th style="width:130px"></th>
            @foreach($days as $d)
                <th class="{{ $d->isSameDay($today) ? 'today' : '' }}"><a href="{{ route('schedule', ['view' => 'day', 'date' => $d->toDateString()]) }}">{{ $d->format('D n/j') }}</a></th>
            @endforeach
        </tr>
        @foreach($techs as $tech)
            @php $byDay = $jobs->get($tech->id, collect())->groupBy(fn ($j) => $local($j->scheduled_start)->toDateString()); @endphp
            <tr>
                <th style="text-align:left;text-transform:none;font-size:14px;color:var(--ink)"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $colors[$tech->id] }}"></span> {{ $tech->name }}</th>
                @foreach($days as $d)
                    <td class="cell drop-week {{ $d->isSameDay($today) ? 'today' : '' }}" data-tech="{{ $tech->id }}" data-tech-name="{{ $tech->name }}" data-date="{{ $d->toDateString() }}">
                        @foreach($byDay->get($d->toDateString(), collect()) as $job)
                            @php $done = in_array($job->status, ['completed', 'closed'], true); $s = $local($job->scheduled_start); @endphp
                            <a class="chip {{ $done ? 'done' : '' }}" href="{{ route('queue.show', $job) }}" style="background:{{ $colors[$tech->id] }}"
                               @unless($done) draggable="true" data-id="{{ $job->id }}" data-ref="{{ $job->reference }}" data-time="{{ $s->format('H:i') }}" @endunless
                               title="{{ $job->description }}">{{ $s->format('g:i') }} {{ $job->tradeLabel() }} · {{ $job->property?->name }}</a>
                        @endforeach
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>
    </div>
    <div class="legend"><span>Drag a job to another day or tech; it keeps its time. Click a day for the hour-by-hour view.</span></div>
@endif
</div>

<aside class="sched-side card">
    <h3 style="margin:0 0 4px">Not booked yet ({{ $unscheduled->count() }})</h3>
    <p class="muted" style="margin:0 0 6px">@if($view === 'day')Drag one onto a tech's column to book it.@else Open the day view to drag these onto the calendar.@endif</p>
    @forelse($unscheduled as $wo)
        <div class="card-job {{ $wo->urgency }}" draggable="{{ $view === 'day' ? 'true' : 'false' }}" data-id="{{ $wo->id }}" data-ref="{{ $wo->reference }}" data-new="1">
            <a href="{{ route('queue.show', $wo) }}"><b>{{ $wo->reference }}</b></a> · {{ $wo->tradeLabel() }}
            @if($wo->urgency !== 'routine')<span class="pill {{ $wo->urgency }}">{{ $wo->urgency }}</span>@endif
            <div class="muted">{{ $wo->property?->name ?? $wo->address_text }} {{ $wo->unit?->name }}</div>
            <div class="muted">{{ $wo->status === 'offered' ? 'Times texted, waiting on tenant' : ($wo->review_reason ?? 'New') }}</div>
        </div>
    @empty
        <p>Everything is booked.</p>
    @endforelse
</aside>
</div>
<div id="toast"></div>

@push('scripts')
<script>
(() => {
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const pxPerMin = {{ $pxPerMin }}, dayStart = {{ $dayStart }}, dayEnd = {{ $dayEnd }}, day = @json($date->toDateString());
    let drag = null;
    const toast = (t) => { const el = document.getElementById('toast'); el.textContent = t; el.style.display = 'block'; };
    const fmt = (m) => { const h = Math.floor(m / 60), mm = String(m % 60).padStart(2, '0'); return `${(h % 12) || 12}:${mm} ${h < 12 ? 'AM' : 'PM'}`; };

    document.querySelectorAll('[draggable=true]').forEach(el => {
        el.addEventListener('dragstart', e => {
            drag = { id: el.dataset.id, ref: el.dataset.ref, time: el.dataset.time, grab: el.dataset.new ? 0 : e.offsetY };
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', el.dataset.id);
        });
    });

    async function send(techId, start, label) {
        if (!confirm(`${drag.ref}: ${label}?\n\nThe tenant will get a text with the new time.`)) return;
        toast('Saving…');
        const res = await fetch(@json(route('schedule.move')), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ work_order_id: drag.id, technician_id: techId, start }),
        });
        const body = await res.json().catch(() => ({}));
        if (!res.ok) { toast(body.message || 'That did not save.'); return; }
        toast(body.message); setTimeout(() => location.reload(), 600);
    }

    const zone = (sel, onDrop) => document.querySelectorAll(sel).forEach(z => {
        z.addEventListener('dragover', e => { if (drag) { e.preventDefault(); z.classList.add('over'); } });
        z.addEventListener('dragleave', () => z.classList.remove('over'));
        z.addEventListener('drop', e => { e.preventDefault(); z.classList.remove('over'); if (drag) onDrop(z, e); drag = null; });
    });

    zone('.drop-day', (z, e) => {
        const y = e.clientY - z.getBoundingClientRect().top - (drag.grab || 0);
        let m = dayStart * 60 + Math.round(y / pxPerMin / 15) * 15;
        m = Math.max(dayStart * 60, Math.min(dayEnd * 60 - 30, m));
        const hh = String(Math.floor(m / 60)).padStart(2, '0'), mm = String(m % 60).padStart(2, '0');
        send(z.dataset.tech, `${day} ${hh}:${mm}`, `${z.dataset.techName} at ${fmt(m)}`);
    });
    zone('.drop-week', (z) => {
        const t = drag.time || '10:00';
        const [h, m] = t.split(':').map(Number);
        send(z.dataset.tech, `${z.dataset.date} ${t}`, `${z.dataset.techName} on ${z.dataset.date} at ${fmt(h * 60 + m)}`);
    });
})();
</script>
@endpush
@endsection
