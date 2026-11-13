@extends('layouts.app')
@section('title', 'Tech efficiency')
@section('wide', true)
@section('content')
<style>
    .eff-nav { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
    .eff-nav h2 { margin:0 auto 0 0; }
    .legend { display:flex; gap:14px; flex-wrap:wrap; font-size:13px; margin-bottom:10px; }
    .legend i { display:inline-block; width:12px; height:12px; border-radius:3px; margin-right:5px; vertical-align:-1px; }
    .c-work { background:#059669; } .c-axiom { background:#34d399; } .c-other { background:#fbbf24; } .c-break { background:#cbd5e1; } .c-idle { background:#fca5a5; }
    .eff-row { display:grid; grid-template-columns:180px 1fr 90px; gap:12px; align-items:center; padding:10px 0; border-bottom:1px solid var(--line); }
    .days { display:grid; grid-template-columns:repeat(auto-fit, minmax(70px, 1fr)); gap:8px; align-items:end; }
    .day { text-align:center; font-size:11px; color:var(--muted); }
    .col { height:120px; display:flex; flex-direction:column-reverse; border-radius:5px; overflow:hidden; background:#f3f4f6; margin-bottom:3px; }
    .col span { display:block; }
    .pct { font-size:22px; font-weight:700; text-align:right; }
    .pct small { display:block; font-size:11px; font-weight:400; color:var(--muted); }
    .good { color:var(--green); } .ok { color:var(--amber); } .low { color:var(--red); }
    @media (max-width:700px) { .eff-row { grid-template-columns:1fr; } .pct { text-align:left; } }
</style>
<div class="eff-nav">
    <h2>Tech efficiency · week of {{ $from->format('M j') }}</h2>
    <a class="btn secondary" href="?week={{ $from->subWeek()->toDateString() }}&location={{ $loc->id }}">&larr;</a>
    <a class="btn secondary" href="?location={{ $loc->id }}">This week</a>
    <a class="btn secondary" href="?week={{ $from->addWeek()->toDateString() }}&location={{ $loc->id }}">&rarr;</a>
    @if($locations->count() > 1)
        <form method="get"><select name="location" onchange="this.form.submit()" style="width:auto">@foreach($locations as $l)<option value="{{ $l->id }}" @selected($l->id === $loc->id)>{{ $l->name }}</option>@endforeach</select></form>
    @endif
</div>
<div class="card">
    <p class="muted" style="margin-top:0">Each bar is one day on the employee clock. Utilization = time on work orders ÷ time clocked in. Drive time to Axiom Property Management jobs counts as time on work orders.</p>
    <div class="legend">
        <span><i class="c-work"></i>On the job</span><span><i class="c-axiom"></i>Driving, Axiom PM jobs</span>
        <span><i class="c-other"></i>Driving, other jobs</span><span><i class="c-break"></i>Break</span><span><i class="c-idle"></i>Clocked in, no job clock</span>
    </div>
    @foreach($rows as $r)
        @php($u = $r['total']['utilization'])
        <div class="eff-row">
            <div>
                <b>{{ $r['tech']->name }}</b>
                <div class="muted">{{ intdiv($r['total']['shift'], 60) }}h {{ $r['total']['shift'] % 60 }}m clocked</div>
                <div class="muted">Last GPS: {{ $r['lastPing'] ? $r['lastPing']->recorded_at->setTimezone($tz)->format('D g:i A') : 'none' }}</div>
            </div>
            <div class="days">
                @foreach($r['days'] as $date => $d)
                    @php($scale = max(1, $d['shift']))
                    <div class="day" title="{{ \Carbon\CarbonImmutable::parse($date)->format('D M j') }}: {{ $d['work'] }} min on the job, {{ $d['travel_axiom'] + $d['travel_other'] }} min driving, {{ $d['break'] }} min break, {{ $d['idle'] }} min no job clock">
                        <div class="col">
                            @if($d['shift'] > 0)
                                <span class="c-work" style="height:{{ 100 * $d['work'] / $scale }}%"></span>
                                <span class="c-axiom" style="height:{{ 100 * $d['travel_axiom'] / $scale }}%"></span>
                                <span class="c-other" style="height:{{ 100 * $d['travel_other'] / $scale }}%"></span>
                                <span class="c-break" style="height:{{ 100 * $d['break'] / $scale }}%"></span>
                                <span class="c-idle" style="height:{{ 100 * $d['idle'] / $scale }}%"></span>
                            @endif
                        </div>
                        {{ \Carbon\CarbonImmutable::parse($date)->format('D j') }}<br>
                        <b>{{ $d['utilization'] !== null ? round($d['utilization'] * 100).'%' : '—' }}</b>
                    </div>
                @endforeach
            </div>
            <div class="pct {{ $u === null ? '' : ($u >= .8 ? 'good' : ($u >= .65 ? 'ok' : 'low')) }}">{{ $u !== null ? round($u * 100).'%' : '—' }}<small>this week</small></div>
        </div>
    @endforeach
    @if($rows->isEmpty())<p class="muted">No active techs at this location.</p>@endif
</div>
@endsection
