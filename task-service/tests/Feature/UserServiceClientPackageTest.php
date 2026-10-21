<?php

use Demo\Microservices\UserServiceClient\AuthenticatedUser;
use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;
use Demo\Microservices\UserServiceClient\Exceptions\UserServiceErrorException;
use Demo\Microservices\UserServiceClient\Exceptions\UserServiceUnavailableException;
use Demo\Microservices\UserServiceClient\TokenSession;
use Demo\Microservices\UserServiceClient\UserServiceClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| El cliente HTTP del user-service vive ahora en un paquete de Composer. Estos
| tests prueban el PAQUETE, no la integración: qué hace cada método, cómo
| traduce los errores y qué cabeceras envía. La integración (que el
| task-service lo use bien) la cubren los tests de la aplicación.
*/

it('resolves the owner of a valid token', function () {
    Http::fake(['*/api/user' => Http::response(['id' => 7, 'name' => 'Luis Ramirez', 'email' => 'luis@example.com'])]);

    $user = app(UserServiceClient::class)->authenticate('token-valido');

    expect($user)->toBe(['id' => 7, 'name' => 'Luis Ramirez', 'email' => 'luis@example.com']);
});

it('returns null when the token is not valid', function () {
    Http::fake(['*/api/user' => Http::response(['error' => 'Unauthenticated.'], 401)]);

    expect(app(UserServiceClient::class)->authenticate('token-falso'))->toBeNull();
});

it('throws an error exception when the user-service answers with a server error', function () {
    Http::fake(['*/api/user' => Http::response(['error' => 'boom'], 500)]);

    app(UserServiceClient::class)->authenticate('token-valido');
})->throws(UserServiceErrorException::class);

it('throws an unavailable exception when the user-service cannot be reached', function () {
    Http::fake(['*' => Http::failedConnection()]);

    app(UserServiceClient::class)->authenticate('token-valido');
})->throws(UserServiceUnavailableException::class);

it('issues a token for valid credentials', function () {
    Http::fake(['*/api/tokens' => Http::response(['token' => 'issued-token'], 201)]);

    $token = app(UserServiceClient::class)->issueToken([
        'email' => 'ana@example.com',
        'password' => 'secreto',
    ]);

    expect($token)->toBe('issued-token');
});

it('throws an error exception when the credentials are rejected', function () {
    Http::fake(['*/api/tokens' => Http::response(['error' => 'Credenciales inválidas'], 401)]);

    app(UserServiceClient::class)->issueToken([
        'email' => 'ana@example.com',
        'password' => 'incorrecta',
    ]);
})->throws(UserServiceErrorException::class);

it('sends the credentials as JSON in the request body', function () {
    Http::fake(['*/api/tokens' => Http::response(['token' => 'issued-token'], 201)]);

    app(UserServiceClient::class)->issueToken([
        'email' => 'ana@example.com',
        'password' => 'secreto',
        'device_name' => 'task-service-web',
    ]);

    Http::assertSent(fn (Request $request) => $request['email'] === 'ana@example.com'
        && $request['password'] === 'secreto'
        && $request['device_name'] === 'task-service-web'
    );
});

it('revokes a token with the bearer authorization header', function () {
    Http::fake(['*/api/tokens' => Http::response([], 204)]);

    app(UserServiceClient::class)->revokeToken('token-a-revocar');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->hasHeader('Authorization', 'Bearer token-a-revocar')
    );
});

it('propagates the correlation id of the incoming request', function () {
    Http::fake(['*/api/user' => Http::response(['id' => 1, 'name' => 'Ana', 'email' => 'ana@example.com'])]);

    test()->withHeader('X-Request-Id', 'traza-123')
        ->withToken('test-token')
        ->getJson('/api/tasks')
        ->assertOk();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Request-Id', 'traza-123'));
});

it('binds the contract to the concrete client', function () {
    expect(app(IdentityProvider::class))->toBeInstanceOf(UserServiceClient::class);
});

it('resolves the same client instance every time', function () {
    expect(app(UserServiceClient::class))->toBe(app(UserServiceClient::class));
});

/*
|--------------------------------------------------------------------------
| El middleware de autenticación
|--------------------------------------------------------------------------
|
| El middleware que autentica preguntando al user-service también es del
| paquete: es la última pieza de la frontera. Estos tests fijan su
| comportamiento público, no sus detalles internos.
|
*/

it('registers the authentication alias owned by the package', function () {
    expect(app('router')->getMiddleware())->toHaveKey('auth.user-service');
});

it('rejects an api request without a token with a 401', function () {
    Http::fake();

    $this->getJson('/api/tasks')
        ->assertUnauthorized()
        ->assertJson(['error' => 'Unauthenticated.']);

    Http::assertNothingSent();
});

it('rejects a request whose token is no longer valid', function () {
    Http::fake(['*/api/user' => Http::response(['error' => 'Unauthenticated.'], 401)]);

    $this->withToken('token-caducado')
        ->getJson('/api/tasks')
        ->assertUnauthorized();

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer token-caducado'));
});

it('leaves the identity of the caller in the request attributes', function () {
    Http::fake(['*/api/user' => Http::response(['id' => 7, 'name' => 'Luis', 'email' => 'luis@example.com'])]);

    $this->withToken('token-valido')
        ->getJson('/api/tasks')
        ->assertOk();

    $user = AuthenticatedUser::fromRequest(request());

    expect($user)->not->toBeNull()
        ->and($user->id)->toBe(7)
        ->and($user->name)->toBe('Luis')
        ->and($user->email)->toBe('luis@example.com');
});

it('sends a web visitor to the login page instead of answering a json', function () {
    Http::fake();

    $this->get('/tasks')->assertRedirect(route('login'));

    Http::assertNothingSent();
});

it('drops the web session when the token is no longer valid upstream', function () {
    Http::fake(['*/api/user' => Http::response(['error' => 'Unauthenticated.'], 401)]);

    $this->withSession([TokenSession::SESSION_KEY => 'token-caducado'])
        ->get('/tasks')
        ->assertRedirect(route('login'))
        ->assertSessionMissing(TokenSession::SESSION_KEY);
});

/*
|--------------------------------------------------------------------------
| La sesión con token remoto
|--------------------------------------------------------------------------
|
| TokenSession encapsula el ciclo de vida de la sesión web: emitir el token,
| regenerar el identificador de sesión y revocarlo al cerrar sesión.
|
*/

it('stores the issued token in the session when logging in', function () {
    Http::fake(['*/api/tokens' => Http::response(['token' => 'token-emitido'], 201)]);

    $request = requestWithSession();

    app(TokenSession::class)->login(
        $request,
        ['email' => 'ana@example.com', 'password' => 'secreto'],
        'task-service-web'
    );

    expect($request->session()->get(TokenSession::SESSION_KEY))->toBe('token-emitido');

    Http::assertSent(fn (Request $request) => $request['device_name'] === 'task-service-web');
});

it('revokes the token on the user-service when logging out', function () {
    Http::fake();

    $request = requestWithSession([TokenSession::SESSION_KEY => 'token-emitido']);

    app(TokenSession::class)->logout($request);

    Http::assertSent(fn (Request $sent) => $sent->method() === 'DELETE'
        && $sent->hasHeader('Authorization', 'Bearer token-emitido'));

    expect($request->session()->has(TokenSession::SESSION_KEY))->toBeFalse();
});

it('closes the local session even when the revocation fails', function () {
    fakeUserServiceDown();

    $request = requestWithSession([TokenSession::SESSION_KEY => 'token-emitido']);

    app(TokenSession::class)->logout($request);

    Http::assertSent(fn (Request $sent) => $sent->method() === 'DELETE');

    expect($request->session()->has(TokenSession::SESSION_KEY))->toBeFalse();
});
