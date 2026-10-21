<?php

use Demo\Microservices\UserServiceClient\TokenSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers de autenticación
|--------------------------------------------------------------------------
|
| El task-service autentica preguntando al user-service por HTTP, así que
| ningún test necesita levantar el otro servicio: basta con que Http::fake()
| responda como lo haría.
|
*/

/**
 * Simula que el user-service está vivo y devuelve esta identidad en /api/user.
 *
 * @param  array{id: int, name: string, email: string}|null  $user
 * @return array{id: int, name: string, email: string}
 */
function fakeUserService(?array $user = null): array
{
    $user ??= ['id' => 1, 'name' => 'Ana Torres', 'email' => 'ana.torres@example.com'];

    Http::fake([
        '*/api/user' => Http::response($user),
        '*/api/tokens' => Http::response(['token' => 'issued-token'], 201),
    ]);

    return $user;
}

/**
 * Simula que el user-service está caído.
 */
function fakeUserServiceDown(): void
{
    Http::fake(['*' => Http::failedConnection()]);
}

/**
 * Inicia sesión en la UI web con el token ya resuelto.
 *
 * @param  array{id: int, name: string, email: string}|null  $user
 * @return array{id: int, name: string, email: string}
 */
function loginAs(?array $user = null): array
{
    $user = fakeUserService($user);

    test()->withSession([TokenSession::SESSION_KEY => 'test-token']);

    return $user;
}

/**
 * Construye una request con sesión, para probar el paquete sin pasar por
 * el ciclo de vida de una petición HTTP completa.
 *
 * @param  array<string, mixed>  $sessionData
 */
function requestWithSession(array $sessionData = []): Request
{
    $store = app('session.store');
    $store->start();
    $store->put($sessionData);

    $request = Request::create('/');
    $request->setLaravelSession($store);

    return $request;
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
