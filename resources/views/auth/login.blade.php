@extends('layouts.app')
@section('title', 'Sign in')
@section('content')
<div class="card" style="max-width:380px;margin:50px auto;padding:28px 26px;box-shadow:0 10px 30px rgba(15,42,74,.12);border-top:4px solid #f59e0b">
    <div style="text-align:center;margin-bottom:14px">
        <img src="/img/ams-logo.svg" alt="AMS" width="76" height="76" style="border-radius:16px">
        <h2 style="margin:10px 0 0;color:#0f2a4a">Axiom Maintenance Services</h2>
        <div class="muted">Sign in to continue</div>
    </div>
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
