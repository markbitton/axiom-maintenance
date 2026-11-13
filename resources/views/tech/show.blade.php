@extends('layouts.app')
@section('title', $wo->reference)
@section('content')
@php($tz = $wo->location->timezone)
<div class="card">
    <div class="muted">{{ $wo->reference }} · {{ $wo->scheduled_start ? \App\Services\Scheduling\Texts::window($wo->scheduled_start, $wo->scheduled_end, $tz) : '' }}</div>
    <h2 style="margin:4px 0">{{ $wo->displayAddress() }}</h2>
    @if($wo->urgency !== 'routine')<span class="pill {{ $wo->urgency }}">{{ $wo->urgency }}</span>@endif
    <span class="pill">{{ $wo->trade }}</span>
    <p style="white-space:pre-line">{{ $wo->description }}</p>
    <p class="muted">Tenant: {{ $wo->tenant?->name ?? $wo->contact_name }} · Enter if not home: <b>{{ $wo->permission_to_enter ? 'yes' : 'no' }}</b>{{ $wo->pets ? ' · Pets: '.$wo->pets : '' }}</p>
    <div class="row">
        <a class="btn big-btn" href="{{ $mapsLink }}" target="_blank" rel="noopener">Navigate (Google Maps)</a>
        <a class="btn secondary big-btn" href="{{ $appleLink }}" target="_blank" rel="noopener">Apple Maps</a>
    </div>
    @php($running = $activity && $activity->work_order_id === $wo->id ? $activity->type : null)
    @if($running)
        <p class="flash" style="margin:8px 0">{{ $running === 'travel' ? 'Travel clock' : ($running === 'work' ? 'Job clock' : 'Break') }} running for this job since {{ $activity->started_at->setTimezone($tz)->format('g:i A') }}.</p>
    @endif
    @if(in_array($wo->status, ['scheduled']) && $running !== 'travel')
        <form method="post" action="{{ route('tech.onmyway', $wo) }}" data-gps>@csrf<button class="big-btn secondary">On my way (starts travel clock, texts the tenant)</button></form>
    @endif
    @if(in_array($wo->status, ['scheduled','en_route','in_progress']) && $running !== 'work')
        <form method="post" action="{{ route('tech.start', $wo) }}" data-gps>@csrf<button class="big-btn">{{ $wo->status === 'in_progress' ? 'Restart job clock' : 'Arrived, start job clock' }}</button></form>
    @endif
    @if(($jobMinutes ?? collect())->isNotEmpty())
        <p class="muted">On this job so far: {{ (int) ($jobMinutes['work'] ?? 0) }} min working, {{ (int) ($jobMinutes['travel'] ?? 0) }} min driving.</p>
    @endif
</div>

@if($wo->parts->where('suggested', true)->isNotEmpty())
<div class="card">
    <h3 style="margin-top:0">Likely parts</h3>
    @if($missing)<p class="error">Not on your truck: {{ implode(', ', $missing) }}</p>@endif
    @foreach($wo->parts->where('suggested', true) as $p)<div>{{ $p->part_name }} <span class="muted">{{ round(100 * ($p->suggestion_confidence ?? 0)) }}%</span></div>@endforeach
</div>
@endif

@if($photosEnabled)
<div class="card">
    <h3 style="margin-top:0">Photos</h3>
    <div class="row">
        <div><label>Before</label><input type="file" accept="image/*" capture="environment" data-kind="before"></div>
        <div><label>After</label><input type="file" accept="image/*" capture="environment" data-kind="after"></div>
    </div>
    <p class="muted" id="photo-status">{{ $wo->photos->count() }} photo(s) on this job.</p>
</div>
@endif

<div class="card">
    <h3 style="margin-top:0">Message tenant</h3>
    <p class="muted">Sent from the Axiom number. Your phone number is never shared.</p>
    <div class="thread">
        @foreach($wo->messages as $m)
            <div class="msg {{ $m->direction }}">{{ $m->body }}<div class="muted">{{ $m->created_at->setTimezone($tz)->format('M j g:i A') }}</div></div>
        @endforeach
    </div>
    <form class="row" method="post" action="{{ route('tech.message', $wo) }}">@csrf<div><input name="body" required maxlength="600" placeholder="Type a message"></div><div style="flex:0"><button>Send</button></div></form>
</div>

@if(in_array($wo->status, ['scheduled','en_route','in_progress']))
<form class="card" method="post" action="{{ route('tech.complete', $wo) }}" data-gps="optional">
    @csrf
    <h3 style="margin-top:0">Finish job</h3>
    <label>What did you do?</label>
    <textarea name="notes" rows="3" required></textarea>
    <label>Parts used</label>
    @foreach($wo->parts as $p)
        <div class="row" style="align-items:center"><div>{{ $p->part_name }}</div><div style="flex:0;min-width:90px"><input type="number" min="0" max="99" name="used[{{ $p->id }}]" value="0"></div></div>
    @endforeach
    <div class="row">
        <div><select name="extra_part_id"><option value="">Other part…</option>@foreach($catalog as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
        <div style="flex:0;min-width:90px"><input type="number" min="1" max="99" name="extra_qty" value="1"></div>
    </div>
    <label>Labor minutes (blank = your job clock)</label>
    <input type="number" name="labor_minutes" min="0">
    <button class="big-btn">Complete job (stops the job clock)</button>
</form>

<form class="card" method="post" action="{{ route('tech.pause', $wo) }}" data-gps="optional">
    @csrf
    <h3 style="margin-top:0">Need to come back?</h3>
    <p class="muted">Stops the job clock and sends the job back to the office to book another visit. Time and parts so far can be billed now; the next visit bills only what's new.</p>
    <label>What's done and what's left?</label>
    <textarea name="notes" rows="2" required></textarea>
    <button class="big-btn secondary">Leave for now, needs another visit</button>
</form>
@endif

@if($history->isNotEmpty())
<div class="card"><h3 style="margin-top:0">Past jobs at this unit</h3>
    @foreach($history as $h)<div class="muted">{{ $h->created_at->format('M j, Y') }} · {{ $h->trade }} · {{ \Illuminate\Support\Str::limit($h->completion_notes ?: $h->description, 80) }}</div>@endforeach
</div>
@endif
@endsection

@push('scripts')
@include('tech._gps')
<script>
document.querySelectorAll('input[type=file][data-kind]').forEach(function (input) {
    input.addEventListener('change', async function () {
        const status = document.getElementById('photo-status');
        const token = document.querySelector('input[name=_token]').value;
        for (const file of input.files) {
            status.textContent = 'Uploading…';
            const blob = await shrink(file);
            const res = await fetch('{{ route('tech.photo.url', $wo) }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }, body: JSON.stringify({ content_type: 'image/jpeg' }) });
            const { upload_url, photo_key } = await res.json();
            const put = await fetch(upload_url, { method: 'PUT', body: blob, headers: { 'Content-Type': 'image/jpeg' } });
            if (!put.ok) { status.textContent = 'Upload failed. Try again.'; return; }
            await fetch('{{ route('tech.photo.saved', $wo) }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }, body: JSON.stringify({ photo_key, kind: input.dataset.kind, content_type: 'image/jpeg', size: blob.size }) });
            status.textContent = 'Photo saved.';
        }
    });
});
// Shrink to about 1600px on the phone so uploads are fast and storage stays small.
function shrink(file) {
    return new Promise(function (resolve) {
        const img = new Image();
        img.onload = function () {
            const scale = Math.min(1, 1600 / Math.max(img.width, img.height));
            const c = document.createElement('canvas');
            c.width = Math.round(img.width * scale); c.height = Math.round(img.height * scale);
            c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
            c.toBlob(function (b) { resolve(b || file); }, 'image/jpeg', 0.82);
        };
        img.onerror = function () { resolve(file); };
        img.src = URL.createObjectURL(file);
    });
}
</script>
@endpush
