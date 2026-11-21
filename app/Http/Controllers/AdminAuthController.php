<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminAuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login', [
            'setup' => empty(config('cms.admin_password_hash')),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        if (! password_verify((string) $data['password'], (string) config('cms.admin_password_hash'))) {
            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        $request->session()->regenerate();
        $request->session()->put('admin_authenticated', true);

        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->invalidate();

        return redirect()->route('login');
    }
}
