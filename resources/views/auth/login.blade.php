@extends('layouts.app')
@section('title', 'Sign in')
@section('content')
<div class="card" style="max-width:380px;margin:40px auto">
    <h2 style="margin-top:0">Axiom Maintenance</h2>
    <form method="post" action="/login">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">
        <label style="font-weight:400"><input type="checkbox" name="remember" value="1" style="width:auto"> Keep me signed in</label>
        <p><button style="width:100%">Sign in</button></p>
    </form>
</div>
@endsection
