<?php

use Demo\Microservices\UserServiceClient\TokenSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/**
 * Emula una petición con sesión iniciada (para probar rutas web protegidas
 * por el middleware que lee el token de la sesión).
 */
function requestWithSession(array $sessionData = []): Request
{
    $request = Request::create('/');

    $store = new Store(
        'test',
        new ArraySessionHandler(120),
    );
    $store->start();
    $store->put($sessionData);
    $store->save();

    Session::setDefaultDriver('array');
    $request->setLaravelSession($store);

    return $request;
}

/**
 * Inicia sesión como un usuario devuelto por el user-service.
 */
function loginAs(array $user = ['id' => 1, 'name' => 'Ana Torres', 'email' => 'ana.torres@example.com']): void
{
    Http::fake([
        '*/api/user' => Http::response($user, 200),
    ]);

    $token = 'tok-'.Str::random(40);
    session()->put(TokenSession::SESSION_KEY, $token);
}
