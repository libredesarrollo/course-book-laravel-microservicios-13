<?php

namespace Demo\Microservices\UserServiceClient\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SsoStartController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        // dd(config('user-service-client.url'));
        $client = (string) config('user-service-client.client');
        $base = rtrim((string) config('user-service-client.url'), '/');
        $callback = config('user-service-client.callback_url');

        if (! is_string($callback) || $callback === '') {
            $callback = url('/sso/callback');
        }

        $authorize = $base.'/authorize?'.http_build_query([
            'client' => $client,
            'redirect_uri' => $callback,
        ]);
        
        return redirect()->away($authorize);
    }
}
