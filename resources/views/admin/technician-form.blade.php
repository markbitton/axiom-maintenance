@extends('layouts.app')
@section('title', $user->exists ? 'Edit '.$user->name : 'Add person')
@section('content')
<form class="card" method="post" action="{{ $user->exists ? route('admin.technicians.update', $user) : route('admin.technicians.store') }}">
    @csrf @if($user->exists) @method('PUT') @endif
    <h3 style="margin-top:0">{{ $user->exists ? 'Edit '.$user->name : 'Add person' }}</h3>
    <div class="row">
        <div><label>Name</label><input name="name" value="{{ old('name', $user->name) }}" required></div>
        <div><label>Email</label><input name="email" type="email" value="{{ old('email', $user->email) }}" required></div>
        <div><label>{{ $user->exists ? 'New password (leave blank to keep)' : 'Password' }}</label><input name="password" type="password" autocomplete="new-password"></div>
    </div>
    <div class="row">
        <div><label>Role</label><select name="role">@foreach(\App\Models\User::ROLES as $r)<option value="{{ $r }}" @selected(old('role', $user->role) === $r)>{{ ucfirst($r) }}</option>@endforeach</select></div>
        <div><label>Mobile (for emergency alerts and call forwarding; never shown to tenants)</label><input name="phone" value="{{ old('phone', $user->phone) }}"></div>
        <div><label>Location</label><select name="location_id">@foreach($locations as $l)<option value="{{ $l->id }}" @selected(old('location_id', $user->location_id) == $l->id)>{{ $l->name }}</option>@endforeach</select></div>
    </div>
    <label style="font-weight:400"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" style="width:auto" @checked(old('active', $user->exists ? $user->active : true))> Active</label>

    <h4>Technician details</h4>
    <p class="muted">Only used when the role is Tech.</p>
    <div class="row">
        <div><label>Start of day address</label><input name="start_address" value="{{ old('start_address', $tech->start_address) }}"></div>
        <div><label>Latitude</label><input name="start_lat" value="{{ old('start_lat', $tech->start_lat) }}"></div>
        <div><label>Longitude</label><input name="start_lng" value="{{ old('start_lng', $tech->start_lng) }}"></div>
    </div>
    <div class="row">
        <div><label>Day starts</label><input name="work_start" type="time" value="{{ old('work_start', substr($tech->work_start ?? '08:00', 0, 5)) }}"></div>
        <div><label>Day ends</label><input name="work_end" type="time" value="{{ old('work_end', substr($tech->work_end ?? '17:00', 0, 5)) }}"></div>
        <div><label>Max jobs a day</label><input name="max_jobs_per_day" type="number" min="1" max="20" value="{{ old('max_jobs_per_day', $tech->max_jobs_per_day ?? 8) }}"></div>
    </div>
    <label>Work days</label>
    @php($days = $tech->exists ? $tech->workDays() : [1,2,3,4,5])
    @foreach([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $n => $d)
        <label style="display:inline;font-weight:400;margin-right:10px"><input type="checkbox" name="work_days[]" value="{{ $n }}" style="width:auto" @checked(in_array($n, $days))> {{ $d }}</label>
    @endforeach
    <label style="font-weight:400"><input type="hidden" name="on_call" value="0"><input type="checkbox" name="on_call" value="1" style="width:auto" @checked(old('on_call', $tech->on_call))> On call for emergencies</label>

    <label>Skills (0 none, 1 basic, 2 solid, 3 expert)</label>
    <div class="row">
        @foreach($skills as $s)
            <div><span class="muted">{{ $s->name }}</span>
                <select name="skills[{{ $s->id }}]">@for($i = 0; $i <= 3; $i++)<option value="{{ $i }}" @selected(($tech->exists ? $tech->skillLevel($s->slug) : 0) === $i)>{{ $i }}</option>@endfor</select>
            </div>
        @endforeach
    </div>
    <p><button>Save</button> <a class="btn secondary" href="{{ route('admin.technicians') }}">Cancel</a></p>
</form>
@endsection
