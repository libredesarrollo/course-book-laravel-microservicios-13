<?php

namespace App\Http\Controllers;

use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;
use Demo\Microservices\UserServiceClient\TokenSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SsoCallbackController extends Controller
{
    public function __construct(
        private readonly IdentityProvider $users,
        private readonly TokenSession $session,
    ) {}

    public function callback(Request $request): RedirectResponse
    {
        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return redirect()->route('login');
        }

        $result = $this->users->exchangeCode($code);

        if (! is_array($result) || ! is_string($result['token'] ?? null) || $result['token'] === '') {
            return redirect()->route('login');
        }

        $this->session->loginWithToken($request, $result['token']);

        return redirect()->intended(route('facturas.index'));
    }
}
