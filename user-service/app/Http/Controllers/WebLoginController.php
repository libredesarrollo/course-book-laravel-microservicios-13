<?php

namespace App\Http\Controllers;

use App\Services\SsoTicketIssuer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class WebLoginController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            $client = $request->query('client');
            $redirectUri = $request->query('redirect_uri');

            if (is_string($client) && $client !== '' && is_string($redirectUri) && $redirectUri !== '') {
                return redirect()->to(route('sso.authorize', ['client' => $client, 'redirect_uri' => $redirectUri]));
            }

            return redirect()->route('home');
        }

        return view('auth.login', [
            'client' => $request->query('client'),
            'redirectUri' => $request->query('redirect_uri'),
        ]);
    }

    public function login(Request $request, SsoTicketIssuer $issuer): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'client' => ['nullable', 'string'],
            'redirect_uri' => ['nullable', 'string'],
        ]);

        if (! Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']], true)) {
            Log::warning('idp.login.failed', ['email' => $credentials['email'], 'request_id' => $request->attributes->get('request_id')]);

            return back()->withErrors(['email' => 'Credenciales inválidas.'])->withInput($request->only('email', 'client', 'redirect_uri'));
        }

        $request->session()->regenerate();

        Log::info('idp.login.succeeded', ['user_id' => Auth::id(), 'request_id' => $request->attributes->get('request_id')]);

        $client = $credentials['client'] ?? null;
        $redirectUri = $credentials['redirect_uri'] ?? null;

        if (is_string($client) && $client !== '' && is_string($redirectUri) && $redirectUri !== '') {
            $code = $issuer->issue(Auth::user(), $client, $redirectUri);

            $separator = str_contains($redirectUri, '?') ? '&' : '?';

            return redirect()->away($redirectUri.$separator.'code='.urlencode($code));
        }

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $redirectUri = $request->query('redirect_uri');

        if (is_string($redirectUri) && $redirectUri !== '') {
            return redirect()->away($redirectUri);
        }

        return redirect()->route('login');
    }
}
