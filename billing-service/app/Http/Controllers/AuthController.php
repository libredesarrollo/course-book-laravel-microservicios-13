<?php

namespace App\Http\Controllers;

use Demo\Microservices\UserServiceClient\Exceptions\UserServiceErrorException;
use Demo\Microservices\UserServiceClient\TokenSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (session()->has(TokenSession::SESSION_KEY)) {
            return redirect()->route('facturas.index');
        }

        return view('auth.login');
    }

    public function store(Request $request, TokenSession $session): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $session->login($request, $credentials, 'billing-web');
        } catch (UserServiceErrorException) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Credenciales inválidas.']);
        }

        return redirect()->intended(route('facturas.index'));
    }

    public function destroy(Request $request, TokenSession $session): RedirectResponse
    {
        $session->logout($request);

        return redirect()->route('login');
    }
}
