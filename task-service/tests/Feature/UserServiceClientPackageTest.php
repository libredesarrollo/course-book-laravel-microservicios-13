<?php

use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;
use Demo\Microservices\UserServiceClient\Exceptions\UserServiceErrorException;
use Demo\Microservices\UserServiceClient\Exceptions\UserServiceUnavailableException;
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
