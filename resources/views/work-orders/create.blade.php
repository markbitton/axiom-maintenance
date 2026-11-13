@extends('layouts.app')
@section('title', 'New work order')
@section('content')
<div class="card" style="max-width:760px">
    <h2 style="margin-top:0">New work order</h2>
    <p class="muted">For calls, walk-ins and things found on inspections. Leave the trade, urgency and length blank and the system will work them out from the description.</p>
    <form method="post" action="{{ route('work-orders.store') }}">
        @csrf
        <input type="hidden" name="location_id" value="{{ $location->id }}">

        <label for="unit_id">Where</label>
        <select id="unit_id" name="unit_id">
            <option value="">Not listed (type the address below)</option>
            @foreach($units as $property => $list)
                <optgroup label="{{ $property }}">
                    @foreach($list as $unit)
                        <option value="{{ $unit->id }}" @selected(old('unit_id') == $unit->id)>{{ $property }} · {{ $unit->name }}@if($unit->tenants->first()) · {{ $unit->tenants->first()->name }}@endif</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        <div id="addr" class="row">
            <div><label for="address_text">Address (if not listed)</label><input id="address_text" name="address_text" value="{{ old('address_text') }}" placeholder="123 Main St #4, Boise"></div>
            <div><label for="contact_name">Contact name</label><input id="contact_name" name="contact_name" value="{{ old('contact_name') }}"></div>
            <div><label for="contact_phone">Contact phone</label><input id="contact_phone" name="contact_phone" value="{{ old('contact_phone') }}"></div>
        </div>

        <label for="description">What's wrong</label>
        <textarea id="description" name="description" rows="4" required placeholder="Kitchen sink is clogged and draining very slowly.">{{ old('description') }}</textarea>

        <div class="row">
            <div><label for="trade">Trade</label>
                <select id="trade" name="trade">
                    <option value="">Let the system decide</option>
                    @foreach(['plumbing' => 'Plumbing', 'electrical' => 'Electrical', 'hvac' => 'HVAC', 'appliance' => 'Appliance', 'locks' => 'Doors & locks', 'pest' => 'Pest', 'general' => 'General'] as $v => $l)
                        <option value="{{ $v }}" @selected(old('trade') === $v)>{{ $l }}</option>
                    @endforeach
                </select></div>
            <div><label for="urgency">Urgency</label>
                <select id="urgency" name="urgency">
                    <option value="">Let the system decide</option>
                    <option value="routine" @selected(old('urgency') === 'routine')>Routine</option>
                    <option value="urgent" @selected(old('urgency') === 'urgent')>Urgent (within a day)</option>
                    <option value="emergency" @selected(old('urgency') === 'emergency')>Emergency (now)</option>
                </select></div>
            <div><label for="estimated_minutes">How long it should take</label>
                <select id="estimated_minutes" name="estimated_minutes">
                    <option value="">Let the system decide</option>
                    @foreach($minutes as $m)
                        <option value="{{ $m }}" @selected(old('estimated_minutes') == $m)>{{ $m < 60 ? $m.' min' : ($m / 60).' '.\Illuminate\Support\Str::plural('hour', $m / 60) }}</option>
                    @endforeach
                </select></div>
        </div>

        <div class="row">
            <div><label for="best_times">Best times for the tenant (optional)</label><input id="best_times" name="best_times" value="{{ old('best_times') }}" placeholder="Mornings, not Fridays"></div>
            <div style="flex:0 0 auto"><label style="display:flex;gap:8px;align-items:center;font-weight:400;margin-top:30px">
                <input type="checkbox" name="permission_to_enter" value="1" style="width:auto" @checked(old('permission_to_enter'))> OK to enter if no one is home</label></div>
        </div>

        <label>Then</label>
        <label style="font-weight:400;display:flex;gap:8px"><input type="radio" name="then" value="auto" style="width:auto" @checked(old('then', 'auto') === 'auto')>
            Schedule it automatically (books the best time, or texts the tenant choices if we can't enter)</label>
        <label style="font-weight:400;display:flex;gap:8px"><input type="radio" name="then" value="place" style="width:auto" @checked(old('then') === 'place')>
            Show me the best times and I'll pick</label>

        <div style="margin-top:14px"><button>Save work order</button> <a class="btn secondary" href="{{ route('schedule') }}">Cancel</a></div>
    </form>
</div>
@push('scripts')
<script>
    const u = document.getElementById('unit_id'), a = document.getElementById('addr');
    const sync = () => { a.style.display = u.value ? 'none' : ''; };
    u.addEventListener('change', sync); sync();
</script>
@endpush
@endsection
