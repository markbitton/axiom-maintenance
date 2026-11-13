@extends('layouts.app')
@section('title', $wo->reference)
@section('content')
@php($tz = $wo->location->timezone)
<div class="card">
    <div class="row" style="align-items:center">
        <h2 style="margin:0">{{ $wo->reference }} <span class="pill {{ $wo->urgency }}">{{ $wo->urgency }}</span> <span class="pill">{{ str_replace('_',' ',$wo->status) }}</span></h2>
    </div>
    @if($wo->review_reason)<p class="error" style="margin-top:10px"><b>Needs a person:</b> {{ $wo->review_reason }}</p>@endif
    <p style="white-space:pre-line">{{ $wo->description }}</p>
    <div class="grid">
        <div><span class="muted">Where</span><br>{{ $wo->displayAddress() ?: 'Unknown' }}</div>
        <div><span class="muted">Tenant</span><br>{{ $wo->tenant?->name ?? $wo->contact_name ?? 'Unknown' }}<br><span class="muted">{{ $wo->tenantPhone() }}</span></div>
        <div><span class="muted">Permission to enter</span><br>{{ $wo->permission_to_enter ? 'Yes' : 'No' }}{{ $wo->pets ? ' · Pets: '.$wo->pets : '' }}</div>
        <div><span class="muted">Scheduled</span><br>{{ $wo->scheduled_start ? \App\Services\Scheduling\Texts::window($wo->scheduled_start, $wo->scheduled_end, $tz).' · '.$wo->technician?->name : 'Not yet' }}</div>
        <div><span class="muted">Source</span><br>{{ str_replace('_',' ', $wo->source) }}{{ $wo->rm_service_issue_id ? ' · RM #'.$wo->rm_service_issue_id : '' }}</div>
    </div>
    @if($wo->triage)
        <p class="muted" style="margin-top:10px">Triage ({{ $wo->triage['source'] ?? '' }}, {{ round(100 * ($wo->triage_confidence ?? 0)) }}% sure): {{ $wo->triage['summary'] ?? '' }}</p>
    @endif
    <form class="row" method="post" action="{{ route('queue.update', $wo) }}">
        @csrf @method('PATCH')
        <div><label>Trade</label><select name="trade">@foreach(\App\Models\Skill::SLUGS as $t)<option @selected($wo->trade === $t)>{{ $t }}</option>@endforeach</select></div>
        <div><label>Urgency</label><select name="urgency">@foreach(['emergency','urgent','routine'] as $u)<option @selected($wo->urgency === $u)>{{ $u }}</option>@endforeach</select></div>
        <div><label>Minutes on site</label><input type="number" name="estimated_minutes" value="{{ $wo->estimated_minutes }}"></div>
        <div style="flex:0"><button class="secondary">Save</button></div>
    </form>
</div>

@if(in_array($wo->status, \App\Models\WorkOrder::OPEN_STATUSES))
<div class="card">
    <h3 style="margin-top:0">Book it</h3>
    @if($suggestions)
        <p class="muted">Best openings, best first. Booking texts the tenant.</p>
        @foreach($suggestions as $s)
            <form method="post" action="{{ route('queue.book', $wo) }}" style="display:inline-block;margin:3px">
                @csrf <input type="hidden" name="technician_id" value="{{ $s->technician->id }}"><input type="hidden" name="start" value="{{ $s->start->setTimezone($tz)->format('Y-m-d H:i') }}">
                <button class="secondary">{{ \App\Services\Scheduling\Texts::window($s->start, $s->end, $tz) }} · {{ $s->technician->name }}</button>
            </form>
        @endforeach
        <form method="post" action="{{ route('queue.reoffer', $wo) }}" style="margin-top:8px">@csrf<button>Text the tenant 3 options instead</button></form>
    @else
        <p>No automatic openings found. Pick a time by hand:</p>
    @endif
    <form class="row" method="post" action="{{ route('queue.book', $wo) }}" style="margin-top:10px">
        @csrf
        <div><label>Tech</label><select name="technician_id">@foreach($techs as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div>
        <div><label>Arrival window starts ({{ $tz }})</label><input type="datetime-local" name="start" required></div>
        <div style="flex:0"><button>Book</button></div>
    </form>
    <div class="row" style="margin-top:12px">
        @if($wo->review_reason)<form method="post" action="{{ route('queue.resolve', $wo) }}" style="flex:0">@csrf<button class="secondary">Clear from queue</button></form>@endif
        <form method="post" action="{{ route('queue.cancel', $wo) }}" style="flex:0" onsubmit="return confirm('Cancel this work order?')">@csrf<button class="danger">Cancel job</button></form>
    </div>
</div>
@endif

<div class="card">
    <h3 style="margin-top:0">Messages</h3>
    <div class="thread">
        @forelse($wo->messages as $m)
            <div class="msg {{ $m->direction }}">{{ $m->body }}<div class="muted">{{ $m->direction === 'inbound' ? 'Tenant' : ($m->user?->name ?? 'Automatic') }} · {{ $m->created_at->setTimezone($tz)->format('M j g:i A') }}{{ $m->status === 'failed' ? ' · FAILED' : '' }}</div></div>
        @empty <p class="muted">No messages yet.</p>
        @endforelse
    </div>
    <form class="row" method="post" action="{{ route('queue.message', $wo) }}">@csrf<div><input name="body" placeholder="Text the tenant from the Axiom number" required maxlength="600"></div><div style="flex:0"><button>Send</button></div></form>
</div>

@if($wo->parts->isNotEmpty())
<div class="card"><h3 style="margin-top:0">Parts</h3>
    @foreach($wo->parts as $p)<div>{{ $p->part_name }} × {{ $p->quantity }} <span class="muted">{{ $p->used ? 'used' : ($p->suggested ? 'suggested '.round(100 * ($p->suggestion_confidence ?? 0)).'%' : '') }}</span></div>@endforeach
</div>
@endif

<div class="card">
    <h3 style="margin-top:0">Time &amp; billing</h3>
    @if($wo->timeEntries->whereIn('type', ['travel', 'work'])->isNotEmpty())
        <table>
            <tr><th>When</th><th>Tech</th><th>Clock</th><th style="text-align:right">Min</th><th>Billed</th></tr>
            @foreach($wo->timeEntries->whereIn('type', ['travel', 'work'])->sortBy('started_at') as $e)
                <tr>
                    <td>{{ $e->started_at->setTimezone($tz)->format('M j g:i A') }}{{ $e->ended_at ? '' : ' (running)' }}</td>
                    <td>{{ $e->technician?->name }}</td>
                    <td>{{ $e->type === 'work' ? 'On the job' : 'Driving' }}</td>
                    <td style="text-align:right">{{ $e->minutes() }}</td>
                    <td>@if($e->invoice)<a href="{{ route('invoices.show', $e->invoice) }}">{{ $e->invoice->number }}</a>@else<span class="muted">not yet</span>@endif</td>
                </tr>
            @endforeach
        </table>
    @else
        <p class="muted">No time on this job yet. It's tracked when the tech taps "On my way" and "Arrived".</p>
    @endif

    @if($wo->invoices->isNotEmpty())
        <p style="margin-bottom:4px"><b>Invoices</b></p>
        @foreach($wo->invoices->sortBy('id') as $inv)
            <div><a href="{{ route('invoices.show', $inv) }}">{{ $inv->number }}</a> · ${{ number_format((float) $inv->total, 2) }} · <span class="pill {{ $inv->status === 'paid' ? 'ok' : ($inv->status === 'void' ? 'emergency' : '') }}">{{ $inv->status }}</span> <span class="muted">{{ $inv->created_at->setTimezone($tz)->format('M j') }}</span></div>
        @endforeach
    @endif

    @php($billable = collect($unbilled)->where('kind', '!=', 'fee'))
    @if($billable->isNotEmpty())
        <p style="margin-bottom:4px"><b>Not billed yet</b> <span class="muted">· ${{ number_format(collect($unbilled)->sum('amount'), 2) }}</span></p>
        @foreach($unbilled as $l)<div class="muted">{{ $l['description'] }} · ${{ number_format($l['amount'], 2) }}</div>@endforeach
        <form method="post" action="{{ route('invoices.store', $wo) }}" style="margin-top:8px">@csrf
            <div class="row"><div><input name="notes" placeholder="Note on the invoice (optional)" maxlength="2000"></div><div style="flex:0"><button>Create invoice for unbilled work</button></div></div>
        </form>
        <p class="muted">Only what isn't on an earlier invoice is billed, so you can invoice part of a job now and the rest after the next visit.</p>
    @elseif($wo->invoices->isNotEmpty())
        <p class="muted">Everything on this job so far has been billed.</p>
    @endif
</div>

@if($photoUrls->isNotEmpty())
<div class="card"><h3 style="margin-top:0">Photos</h3>@foreach($photoUrls as $url)<img src="{{ $url }}" alt="Job photo" style="max-width:220px;border-radius:8px;margin:4px">@endforeach</div>
@endif

<div class="card"><h3 style="margin-top:0">History</h3>
    @foreach($wo->activity->sortByDesc('id') as $a)<div class="muted">{{ $a->created_at->setTimezone($tz)->format('M j g:i A') }} · {{ str_replace('_',' ',$a->event) }}</div>@endforeach
</div>
@endsection
