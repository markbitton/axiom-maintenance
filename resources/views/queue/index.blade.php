@extends('layouts.app')
@section('title', 'Queue')
@section('content')
<div class="card">
    <h3 style="margin-top:0">Needs a person ({{ $review->count() }})</h3>
    <p class="muted">Everything else is being booked automatically.</p>
    @if($review->isEmpty())
        <p>Nothing needs you right now.</p>
    @else
    <table>
        <tr><th>Ref</th><th>Why</th><th>Request</th><th class="hide-sm">Waiting</th></tr>
        @foreach($review as $wo)
        <tr>
            <td><a href="{{ route('queue.show', $wo) }}">{{ $wo->reference }}</a>@if($wo->urgency !== 'routine')<br><span class="pill {{ $wo->urgency }}">{{ $wo->urgency }}</span>@endif</td>
            <td><b>{{ $wo->review_reason }}</b></td>
            <td>{{ \Illuminate\Support\Str::limit($wo->description, 90) }}<div class="muted">{{ $wo->displayAddress() }}</div></td>
            <td class="hide-sm muted">{{ $wo->needs_review_at?->diffForHumans() }}</td>
        </tr>
        @endforeach
    </table>
    @endif
</div>

<div class="card">
    <h3 style="margin-top:0">Waiting on tenant to pick a time ({{ $waiting->count() }})</h3>
    @foreach($waiting as $wo)
        <div><a href="{{ route('queue.show', $wo) }}">{{ $wo->reference }}</a> · {{ \Illuminate\Support\Str::limit($wo->description, 60) }} <span class="muted">offered {{ $wo->updated_at->diffForHumans() }}</span></div>
    @endforeach
</div>

<div class="card">
    <form class="row" method="get" style="align-items:center"><h3 style="margin:0">Day board</h3><input type="date" name="day" value="{{ $day->toDateString() }}" onchange="this.form.submit()" style="max-width:180px"></form>
    <table style="margin-top:10px">
        @foreach($board as $tech)
        <tr>
            <td style="width:160px"><b>{{ $tech->name }}</b>@if($tech->on_call)<br><span class="pill urgent">on call</span>@endif</td>
            <td>
                @forelse($tech->workOrders as $job)
                    <a class="pill {{ $job->urgency }}" style="margin:2px" href="{{ route('queue.show', $job) }}">{{ $job->scheduled_start->setTimezone($tech->location->timezone ?? 'UTC')->format('g:i') }} {{ $job->trade }} {{ $job->reference }}</a>
                @empty <span class="muted">No jobs</span>
                @endforelse
            </td>
        </tr>
        @endforeach
    </table>
</div>
@endsection
