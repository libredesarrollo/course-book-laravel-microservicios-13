<?php

namespace App\Http\Controllers;

use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;
use Demo\Microservices\UserServiceClient\TokenSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SsoBridgeController extends Controller
{
    public function __construct(private readonly IdentityProvider $users) {}

    public function bridge(Request $request): RedirectResponse
    {
        $token = $request->session()->get(TokenSession::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            return redirect()->route('login');
        }

        $billingUrl = rtrim((string) env('BILLING_SERVICE_URL', 'http://127.0.0.1:8002'), '/');
        $callback = $billingUrl.'/sso/callback';

        $result = $this->users->authorizeSso($token, 'billing', $callback);

        if (! is_array($result) || ! is_string($result['code'] ?? null) || $result['code'] === '') {
            return redirect($billingUrl.'/login');
        }

        return redirect($callback.'?code='.urlencode($result['code']));
    }
}
