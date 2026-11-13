@extends('layouts.app')
@section('title', 'Rates & fees')
@section('content')
<style>
    .rates input[type=number] { min-width:80px; }
    .rates td, .rates th { white-space:nowrap; }
    .scroll { overflow-x:auto; }
    .hint { font-size:12px; color:var(--muted); }
</style>
<h2>Rates &amp; fees</h2>
<p class="muted">An invoice uses the most specific hourly rate it can find: the tech's rate for that kind of work, then the tech's own rate, then the rate for the kind of work, then the default. Leave a box blank to fall through to the next one.</p>

<form method="post" action="{{ route('admin.rates.update') }}" class="rates">
    @csrf
    <div class="card">
        <h3 style="margin-top:0">Billing options</h3>
        <div class="row">
            <div><label>Default hourly rate ($)</label><input type="number" step="0.01" min="0" name="settings[default_hourly_rate]" value="{{ $settings['default_hourly_rate'] }}"></div>
            <div><label>Minimum billable time per visit (min)</label><input type="number" min="0" name="settings[minimum_billable_minutes]" value="{{ $settings['minimum_billable_minutes'] }}"></div>
            <div><label>Parts markup (%)</label><input type="number" step="0.1" min="0" name="settings[parts_markup_percent]" value="{{ $settings['parts_markup_percent'] }}"></div>
        </div>
        <div class="row">
            <div><label style="font-weight:400"><input type="checkbox" name="settings[bill_travel]" value="1" style="width:auto" @checked($settings['bill_travel'] === '1')> Bill drive time to the customer</label></div>
            <div><label>Drive time billed at (% of hourly rate)</label><input type="number" min="0" max="200" name="settings[travel_rate_percent]" value="{{ $settings['travel_rate_percent'] }}"></div>
        </div>
    </div>

    <div class="card scroll">
        <h3 style="margin-top:0">Kinds of work</h3>
        <table>
            <tr><th>Work type</th><th>Hourly rate ($)</th><th>Service call fee ($)</th><th>Licensed trade</th></tr>
            @foreach($skills as $s)
                <tr>
                    <td>{{ $s->name }}</td>
                    <td><input type="number" step="0.01" min="0" name="skills[{{ $s->id }}][hourly_rate]" value="{{ $s->hourly_rate > 0 ? $s->hourly_rate : '' }}" placeholder="default"></td>
                    <td><input type="number" step="0.01" min="0" name="skills[{{ $s->id }}][service_call_fee]" value="{{ $s->service_call_fee > 0 ? $s->service_call_fee : '' }}" placeholder="none"></td>
                    <td><input type="hidden" name="skills[{{ $s->id }}][licensed]" value="0"><input type="checkbox" name="skills[{{ $s->id }}][licensed]" value="1" style="width:auto" @checked($s->licensed)></td>
                </tr>
            @endforeach
        </table>
        <p class="hint">The service call fee is charged once per work order, on its first invoice.</p>
    </div>

    <div class="card scroll">
        <h3 style="margin-top:0">Each tech</h3>
        <table>
            <tr><th>Tech</th><th>Own rate ($)</th>@foreach($skills as $s)<th>{{ $s->name }}</th>@endforeach</tr>
            @foreach($techs as $t)
                @php($pivot = $t->skills->keyBy('id'))
                <tr>
                    <td>{{ $t->name }}<div class="hint">{{ $t->location?->name }}</div></td>
                    <td><input type="number" step="0.01" min="0" name="techs[{{ $t->id }}][hourly_rate]" value="{{ $t->hourly_rate }}" placeholder="—"></td>
                    @foreach($skills as $s)
                        @php($p = $pivot->get($s->id)?->pivot)
                        <td><input type="number" step="0.01" min="0" name="techs[{{ $t->id }}][trades][{{ $s->id }}]" value="{{ $p?->hourly_rate }}"
                            placeholder="{{ $p ? '—' : 'n/a' }}" title="{{ $p ? 'Skill level '.$p->level : 'Not one of this tech\'s skills' }}"></td>
                    @endforeach
                </tr>
            @endforeach
        </table>
        <p class="hint">Blank = use the tech's own rate, then the work type's rate. "n/a" means it isn't one of their skills yet.</p>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Home offices</h3>
        <p class="hint">Routes start and end here, and the map centers on it.</p>
        @foreach($locations as $l)
            <label>{{ $l->name }}</label>
            <input name="offices[{{ $l->id }}]" value="{{ $l->office_address }}" placeholder="Street, city, state">
            @if($l->office())<div class="hint">On the map at {{ number_format($l->office_lat, 4) }}, {{ number_format($l->office_lng, 4) }}</div>@endif
        @endforeach
    </div>

    <button class="big-btn">Save rates</button>
</form>
@endsection
