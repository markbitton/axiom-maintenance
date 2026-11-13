@extends('layouts.app')
@section('title', 'My day')
@section('content')
@php($tz = $tech->location->timezone)
@include('tech._clock')
<div class="row" style="align-items:center;margin-bottom:10px">
    <h2 style="margin:0">{{ $day->isToday() ? 'Today' : $day->format('l M j') }}</h2>
    <div style="flex:0;white-space:nowrap">
        <a class="btn secondary" href="?day={{ $day->subDay()->toDateString() }}">‹</a>
        <a class="btn secondary" href="?day={{ $day->addDay()->toDateString() }}">›</a>
    </div>
</div>
@forelse($stops as $i => $job)
    <a class="card" href="{{ route('tech.show', $job) }}" style="display:block;color:inherit">
        <div class="row" style="align-items:center">
            <div style="flex:0;font-size:22px;font-weight:700;color:var(--brand)">{{ $i + 1 }}</div>
            <div>
                <b>{{ $job->scheduled_start->setTimezone($tz)->format('g:i A') }}</b>
                @if($job->urgency !== 'routine')<span class="pill {{ $job->urgency }}">{{ $job->urgency }}</span>@endif
                <span class="pill">{{ $job->trade }}</span>
                @if(in_array($job->status, ['completed','closed']))<span class="pill ok">done</span>@endif
                <div>{{ $job->displayAddress() }}</div>
                <div class="muted">{{ $job->triage['summary'] ?? \Illuminate\Support\Str::limit($job->description, 80) }}</div>
            </div>
        </div>
    </a>
@empty
    <div class="card">No jobs scheduled.</div>
@endforelse
@endsection

@push('scripts')
@include('tech._gps')
@endpush
