@extends('layouts.app')
@section('title', 'People')
@section('content')
<div class="card">
    <div class="row" style="align-items:center"><h3 style="margin:0">People</h3><div style="flex:0"><a class="btn" href="{{ route('admin.technicians.create') }}">Add person</a></div></div>
    <table style="margin-top:10px">
        <tr><th>Name</th><th>Role</th><th class="hide-sm">Location</th><th>Skills</th><th></th></tr>
        @foreach($users as $u)
        <tr>
            <td>{{ $u->name }} @unless($u->active)<span class="pill">inactive</span>@endunless @if($u->technician?->on_call)<span class="pill urgent">on call</span>@endif<div class="muted">{{ $u->email }}</div></td>
            <td>{{ ucfirst($u->role) }}</td>
            <td class="hide-sm">{{ $u->location?->name }}</td>
            <td>@foreach($u->technician?->skills ?? [] as $s)<span class="pill" style="margin:1px">{{ $s->name }} {{ str_repeat('●', $s->pivot->level) }}</span>@endforeach</td>
            <td><a href="{{ route('admin.technicians.edit', $u) }}">Edit</a></td>
        </tr>
        @endforeach
    </table>
</div>
@endsection
