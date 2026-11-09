<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function show()
    {
        return inertia('Auth/Login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($data, (bool) $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'Credenciales inválidas.']);
        }

        if (Auth::user()->is_active === false) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'Usuario deshabilitado.']);
        }

        $request->session()->regenerate();

        return redirect()->intended('/inbox');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
