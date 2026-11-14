@extends('layouts.app')
@section('title', 'Add receipt')
@section('content')
<form class="card" method="post" action="{{ route('receipts.store') }}" enctype="multipart/form-data" style="max-width:640px;margin:0 auto" id="receipt-form">
    @csrf
    <h2 style="margin-top:0">Add a receipt</h2>
    <label for="photo">Photo of the receipt</label>
    <div id="drop" class="drop">
        <input id="photo" type="file" name="photo" accept="image/*,application/pdf" capture="environment">
        <div class="drop-hint">📷 <b>Take a photo</b> or choose a file<span class="hide-sm"> · on a computer, drag the receipt here or paste it (Ctrl+V)</span></div>
        <div class="drop-name muted" id="drop-name"></div>
    </div>
    <p class="muted" style="margin:4px 0 0">{{ $aiReads ? 'We read the store, date and every line from the photo, then you check it.' : 'Snap it now; you\'ll enter what each part cost on the next screen.' }}</p>
    <img id="preview" alt="" style="display:none;max-width:100%;max-height:260px;margin-top:8px;border-radius:8px;border:1px solid var(--line)">

    <div class="row">
        <div><label for="vendor">Store</label><input id="vendor" name="vendor" list="vendors" value="{{ old('vendor', $vendors->count() === 1 ? $vendors->first() : '') }}" placeholder="{{ $aiReads ? 'Read from the photo' : 'Start typing, e.g. Home Depot' }}" autocomplete="off">
            <datalist id="vendors">@foreach($vendors as $v)<option value="{{ $v }}">@endforeach</datalist></div>
        <div><label for="purchased_on">Date</label><input id="purchased_on" type="date" name="purchased_on" value="{{ old('purchased_on', now($wo?->location?->timezone ?? $jobs->first()?->location?->timezone ?? 'America/Boise')->toDateString()) }}"></div>
        <div><label for="total">Total ($)</label><input id="total" type="number" step="0.01" min="0" name="total" value="{{ old('total') }}" placeholder="{{ $aiReads ? 'Read from the photo' : '' }}"></div>
    </div>

    <label>Which jobs is it for? <span class="muted" style="font-weight:400">(one receipt can cover several)</span></label>
    @forelse($jobs as $j)
        <label class="job-pick"><input type="checkbox" name="work_orders[]" value="{{ $j->id }}" @checked($wo && $wo->id === $j->id || in_array($j->id, old('work_orders', [])))>
            <span><b>{{ $j->reference }}</b> · {{ $j->tradeLabel() }} · {{ $j->property?->name ?? $j->address_text }} {{ $j->unit?->name }}
            @if($j->scheduled_start)<span class="muted">· {{ $j->scheduled_start->setTimezone($j->location?->timezone ?? 'UTC')->format('D M j') }}</span>@endif</span></label>
    @empty
        <p class="muted">No recent jobs.</p>
    @endforelse
    <button class="big-btn" id="save-btn">Save receipt</button>
</form>
<style>
    .drop { position:relative; border:2px dashed #cbd5e1; border-radius:10px; padding:22px 14px; text-align:center; background:#f8fafc; transition:.15s; }
    .drop input { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
    .drop.over { border-color:#f59e0b; background:#fffbeb; }
    .drop-hint { color:#334155; }
    .job-pick { display:flex; gap:10px; align-items:flex-start; border:1px solid var(--line); border-radius:8px; padding:9px 10px; margin:6px 0; font-weight:400; cursor:pointer; }
    .job-pick input { width:auto; margin-top:3px; transform:scale(1.25); }
</style>
@endsection
@push('scripts')
<script>
const photo = document.getElementById('photo'), drop = document.getElementById('drop');
function showFile() {
    const f = photo.files[0], img = document.getElementById('preview');
    document.getElementById('drop-name').textContent = f ? f.name : '';
    if (f && f.type.startsWith('image/')) { img.src = URL.createObjectURL(f); img.style.display = 'block'; } else { img.style.display = 'none'; }
}
function useFile(file) {
    if (!file || !(file.type.startsWith('image/') || file.type === 'application/pdf')) return;
    const dt = new DataTransfer(); dt.items.add(file); photo.files = dt.files; showFile();
}
photo.addEventListener('change', showFile);
// Drop anywhere on the page, not only on the box.
['dragenter', 'dragover'].forEach(t => window.addEventListener(t, e => { if ([...(e.dataTransfer?.types || [])].includes('Files')) { e.preventDefault(); drop.classList.add('over'); } }));
['dragleave', 'dragend'].forEach(t => window.addEventListener(t, e => { if (!e.relatedTarget) drop.classList.remove('over'); }));
window.addEventListener('drop', e => { if (e.dataTransfer?.files.length) { e.preventDefault(); drop.classList.remove('over'); useFile(e.dataTransfer.files[0]); } });
window.addEventListener('paste', e => { const f = [...(e.clipboardData?.files || [])][0]; if (f) { e.preventDefault(); useFile(f); } });
document.getElementById('receipt-form').addEventListener('submit', () => { const b = document.getElementById('save-btn'); b.disabled = true; b.textContent = 'Saving…'; });
</script>
@endpush
