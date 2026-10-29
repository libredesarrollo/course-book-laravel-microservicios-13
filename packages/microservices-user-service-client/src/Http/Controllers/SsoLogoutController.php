<?php

namespace Demo\Microservices\UserServiceClient\Http\Controllers;

use Demo\Microservices\UserServiceClient\TokenSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SsoLogoutController extends Controller
{
    public function __invoke(Request $request, TokenSession $session): RedirectResponse
    {
        $base = rtrim((string) config('user-service-client.url'), '/');
        $session->logout($request);

        return redirect()->away($base.'/logout?'.http_build_query([
            'redirect_uri' => url('/'),
        ]));
    }
}
