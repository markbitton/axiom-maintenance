@php
    $step = ($view ?? 'day') === 'week' ? 7 : 1;
    $q = fn ($d, $extra = []) => array_merge(['date' => $d->toDateString(), 'view' => $view ?? 'day'], $extra);
@endphp
<div class="sched-nav">
    <div class="seg">
        <a class="btn secondary" href="{{ route($page, $q($date->subDays($step))) }}" aria-label="Previous">&larr;</a>
        <a class="btn secondary" href="{{ route($page, $q(\Carbon\CarbonImmutable::now($tz))) }}">Today</a>
        <a class="btn secondary" href="{{ route($page, $q($date->addDays($step))) }}" aria-label="Next">&rarr;</a>
    </div>
    <h2>{{ $title }}</h2>
    <form method="get" action="{{ route($page) }}" class="seg">
        <input type="hidden" name="view" value="{{ $view ?? 'day' }}">
        @if(isset($locations) && $locations->count() > 1)
            <select name="location" onchange="this.form.submit()" style="width:auto;padding:6px 8px" aria-label="Location">
                @foreach($locations as $l)
                    <option value="{{ $l->id }}" @selected($l->id === $loc->id)>{{ $l->name }}{{ $l->enabled ? '' : ' (off)' }}</option>
                @endforeach
            </select>
        @endif
        <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()">
    </form>
    <div class="seg">
        <a class="btn {{ $page === 'schedule' && ($view ?? '') === 'day' ? '' : 'secondary' }}" href="{{ route('schedule', $q($date, ['view' => 'day'])) }}">Day</a>
        <a class="btn {{ $page === 'schedule' && ($view ?? '') === 'week' ? '' : 'secondary' }}" href="{{ route('schedule', $q($date, ['view' => 'week'])) }}">Week</a>
        <a class="btn {{ $page === 'map' ? '' : 'secondary' }}" href="{{ route('map', ['date' => $date->toDateString()]) }}">Map</a>
    </div>
</div>
