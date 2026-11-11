@extends('layouts.app')
@section('title', 'Request '.$wo->reference)
@section('content')
@php($tz = $wo->location->timezone)
<div class="card" style="max-width:520px;margin:20px auto">
    <div class="muted">Axiom Maintenance request</div>
    <h2 style="margin:4px 0">{{ $wo->reference }}</h2>
    @php($labels = ['new' => 'Received', 'triaged' => 'Received', 'offered' => 'Waiting for you to pick a time', 'scheduled' => 'Scheduled', 'en_route' => 'Technician on the way', 'in_progress' => 'Work in progress', 'completed' => 'Completed', 'closed' => 'Completed', 'cancelled' => 'Cancelled'])
    <p><span class="pill {{ in_array($wo->status, ['completed','closed']) ? 'ok' : '' }}">{{ $labels[$wo->status] ?? ucfirst($wo->status) }}</span></p>
    @if($wo->scheduled_start && in_array($wo->status, ['scheduled','en_route','in_progress']))
        <p><b>When:</b> {{ \App\Services\Scheduling\Texts::window($wo->scheduled_start, $wo->scheduled_end, $tz) }}</p>
        @if($wo->technician)<p><b>Technician:</b> {{ $wo->technician->first_name }}</p>@endif
    @endif
    @if($wo->status === 'offered')
        <p>Check your texts for time options and reply with the number you want.</p>
    @endif
    <p class="muted">{{ \Illuminate\Support\Str::limit($wo->description, 240) }}</p>
    @foreach($photos as $url)<img src="{{ $url }}" alt="Request photo" style="max-width:100%;border-radius:8px;margin-top:8px">@endforeach
    <p class="muted">Questions? Text or call the number that sent you this link.</p>
</div>
@endsection
