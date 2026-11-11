<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required']);

        if (! Auth::attempt($credentials + ['active' => true], $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'That email and password did not match.'])->onlyInput('email');
        }
        $request->session()->regenerate();

        return redirect()->intended($request->user()->homePath());
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function home(Request $request)
    {
        return redirect($request->user()->homePath());
    }
}
