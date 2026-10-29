<?php

namespace Demo\Microservices\UserServiceClient\Http\Controllers;

use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;
use Demo\Microservices\UserServiceClient\TokenSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SsoCallbackController extends Controller
{
    public function __invoke(Request $request, IdentityProvider $users, TokenSession $session): RedirectResponse
    {
        $code = $request->query('code');
        $client = (string) config('user-service-client.client');
        // dd($code);
        if (! is_string($code) || $code === '') {
            abort(401, 'Código SSO ausente.');
        }

        $result = $users->exchangeCode($code, $client);

        if (! is_array($result)) {
            abort(401, 'No se pudo completar el inicio de sesión.');
        }
        // dd($result['token']);
        $session->loginWithToken($request, $result['token']);

        return redirect()->intended('/');
    }
}
