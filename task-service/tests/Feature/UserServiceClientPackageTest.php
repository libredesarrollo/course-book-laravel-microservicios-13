<?php

use Demo\Microservices\UserServiceClient\AuthenticatedUser;
use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;
use Demo\Microservices\UserServiceClient\Exceptions\UserServiceErrorException;
use Demo\Microservices\UserServiceClient\Exceptions\UserServiceUnavailableException;
use Demo\Microservices\UserServiceClient\Jwt\JwkKeyStore;
use Demo\Microservices\UserServiceClient\Jwt\JwtIdentityProvider;
use Demo\Microservices\UserServiceClient\Jwt\JwtVerifier;
use Demo\Microservices\UserServiceClient\TokenSession;
use Demo\Microservices\UserServiceClient\UserServiceClient;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
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

it('binds the contract to the JWT verification (b0.7)', function () {
    expect(app(IdentityProvider::class))->toBeInstanceOf(JwtIdentityProvider::class);
});

it('still resolves the same HTTP client instance every time', function () {
    expect(app(UserServiceClient::class))->toBe(app(UserServiceClient::class));
});

it('delegates opaque work to the HTTP client inside the JWT provider', function () {
    $provider = app(JwtIdentityProvider::class);

    $clients = (new ReflectionProperty(JwtIdentityProvider::class, 'clients'))
        ->getValue($provider);

    expect($clients)->toBe(app(UserServiceClient::class));
});

it('registers the JWKS store as a singleton', function () {
    expect(app(JwkKeyStore::class))->toBe(app(JwkKeyStore::class))
        ->and(app(JwtVerifier::class))->toBe(app(JwtVerifier::class));
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

it('sends a web visitor to the sso start route instead of answering a json', function () {
    Http::fake();

    $this->get('/tasks')->assertRedirect(route('sso.start'));

    Http::assertNothingSent();
});

it('drops the web session when the token is no longer valid upstream', function () {
    Http::fake(['*/api/user' => Http::response(['error' => 'Unauthenticated.'], 401)]);

    $this->withSession([TokenSession::SESSION_KEY => 'token-caducado'])
        ->get('/tasks')
        ->assertRedirect(route('sso.start'))
        ->assertSessionMissing(TokenSession::SESSION_KEY);
});

/*
|--------------------------------------------------------------------------
| Canje del código SSO
|--------------------------------------------------------------------------
*/

it('exchanges an sso code for a token', function () {
    Http::fake(['*/api/sso/exchange' => Http::response(ssoExchangePayload('token-emitido'), 201)]);

    $result = app(UserServiceClient::class)->exchangeCode('codigo-ss0', 'task');

    expect($result['token'])->toBe('token-emitido');

    Http::assertSent(fn (Request $request) => $request->url() === config('user-service-client.url').'/api/sso/exchange'
        && $request['code'] === 'codigo-ss0'
        && $request['client'] === 'task'
    );
});

it('returns null when the sso code is rejected', function (int $status) {
    Http::fake(['*/api/sso/exchange' => Http::response(['error' => 'Código inválido'], $status)]);

    expect(app(UserServiceClient::class)->exchangeCode('codigo-malo', 'task'))->toBeNull();
})->with([400, 401, 404, 422]);

it('throws an error exception when the sso exchange fails upstream', function () {
    Http::fake(['*/api/sso/exchange' => Http::response(['error' => 'boom'], 500)]);

    app(UserServiceClient::class)->exchangeCode('codigo-ss0', 'task');
})->throws(UserServiceErrorException::class);

/*
|--------------------------------------------------------------------------
| La sesión con token remoto
|--------------------------------------------------------------------------
|
| TokenSession encapsula el ciclo de vida de la sesión web: guardar el token
| que emitió el IdP, regenerar el identificador de sesión y revocarlo al
| cerrar sesión.
|
*/

it('stores the issued token in the session', function () {
    $request = requestWithSession();

    app(TokenSession::class)->loginWithToken($request, 'token-emitido');

    expect($request->session()->get(TokenSession::SESSION_KEY))->toBe('token-emitido');
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

/*
|--------------------------------------------------------------------------
| Resiliencia
|--------------------------------------------------------------------------
|
| Todo el HTTP con el IdP pasa por UserServiceClient::send, así que aquí
| viven los reintentos, el circuit breaker y la caché de verificación del
| middleware. Son las piezas que convierten una caída del IdP de "toda la
| aplicación caída" en "degradación breve y legible".
|
*/

it('retries an idempotent request when the user-service answers with 5xx', function () {
    config([
        'user-service-client.retries' => 2,
        'user-service-client.retry_delay_ms' => 0,
    ]);

    Http::fake([
        '*/api/user' => Http::sequence()
            ->push(['error' => 'boom'], 500)
            ->push(['id' => 7, 'name' => 'Luis', 'email' => 'luis@example.com']),
    ]);

    expect(app(UserServiceClient::class)->authenticate('token-valido'))
        ->toBe(['id' => 7, 'name' => 'Luis', 'email' => 'luis@example.com']);

    Http::assertSentCount(2);
});

it('does not retry when the answer is a legitimate 4xx', function () {
    config(['user-service-client.retries' => 2, 'user-service-client.retry_delay_ms' => 0]);

    Http::fake([
        '*/api/user' => Http::sequence()
            ->push(['error' => 'Unauthenticated.'], 401)
            ->push(['id' => 1, 'name' => 'Otro', 'email' => 'otro@example.com']),
    ]);

    expect(app(UserServiceClient::class)->authenticate('token-falso'))->toBeNull();

    Http::assertSentCount(1);
});

it('never retries the sso exchange because its code is single-use', function () {
    config(['user-service-client.retries' => 2, 'user-service-client.retry_delay_ms' => 0]);

    Http::fake([
        '*/api/sso/exchange' => Http::sequence()
            ->push(['error' => 'boom'], 500)
            ->push(ssoExchangePayload('token-emitido'), 201),
    ]);

    app(UserServiceClient::class)->exchangeCode('codigo-ss0', 'task');
})->throws(UserServiceErrorException::class);

it('opens the circuit after the configured number of consecutive failures', function () {
    config([
        'user-service-client.retries' => 0,
        'user-service-client.retry_delay_ms' => 0,
        'user-service-client.circuit_threshold' => 2,
    ]);

    fakeUserServiceDown();

    $client = app(UserServiceClient::class);

    foreach (range(1, 2) as $_) {
        try {
            $client->authenticate('token');
        } catch (UserServiceUnavailableException) {
            // esperado: el IdP no responde
        }
    }

    try {
        $client->authenticate('token');
        $this->fail('El circuito abierto debía fallar en frío.');
    } catch (UserServiceUnavailableException) {
        // esperado, pero esta vez sin salir a la red
    }

    Http::assertSentCount(2);
});

it('lets traffic through again once the circuit cooldown expires', function () {
    config([
        'user-service-client.retries' => 0,
        'user-service-client.retry_delay_ms' => 0,
        'user-service-client.circuit_threshold' => 1,
    ]);

    Http::fake([
        '*/api/user' => Http::sequence()
            ->push(['error' => 'boom'], 500)
            ->push(['id' => 7, 'name' => 'Luis', 'email' => 'luis@example.com']),
    ]);

    // El 500 también es histórico de infra: alimenta el breaker.
    try {
        app(UserServiceClient::class)->authenticate('token');
    } catch (UserServiceErrorException) {
        // primer 500: abre el circuito
    }

    // Cooldown vencido: la llave ya no bloquea aunque siga guardada.
    Cache::put(
        'user-service-client.circuit.open_until',
        now()->getTimestamp() - 1,
        now()->addSeconds(5),
    );

    expect(app(UserServiceClient::class)->authenticate('token'))
        ->toBe(['id' => 7, 'name' => 'Luis', 'email' => 'luis@example.com']);
});

it('caches the verification result of a valid token for the next request', function () {
    config(['user-service-client.cache_ttl' => 60]);

    Http::fake(['*/api/user' => Http::response(['id' => 7, 'name' => 'Luis', 'email' => 'luis@example.com'])]);

    $this->withToken('token-valido')->getJson('/api/tasks')->assertOk();
    $this->withToken('token-valido')->getJson('/api/tasks')->assertOk();

    Http::assertSentCount(1);
});

it('caches the rejection of an invalid token too', function () {
    config(['user-service-client.cache_ttl' => 60]);

    Http::fake(['*/api/user' => Http::response(['error' => 'Unauthenticated.'], 401)]);

    $this->withToken('token-falso')->getJson('/api/tasks')->assertUnauthorized();
    $this->withToken('token-falso')->getJson('/api/tasks')->assertUnauthorized();

    Http::assertSentCount(1);
});

it('verifies over http on every request when the cache is disabled', function () {
    config(['user-service-client.cache_ttl' => 0]);

    Http::fake(['*/api/user' => Http::response(['id' => 7, 'name' => 'Luis', 'email' => 'luis@example.com'])]);

    $this->withToken('token-valido')->getJson('/api/tasks')->assertOk();
    $this->withToken('token-valido')->getJson('/api/tasks')->assertOk();

    Http::assertSentCount(2);
});

it('answers with a fast 503 when the circuit is open', function () {
    config([
        'user-service-client.retries' => 0,
        'user-service-client.retry_delay_ms' => 0,
        'user-service-client.circuit_threshold' => 1,
    ]);

    Http::fake([
        '*/api/user' => Http::sequence()
            ->push(['error' => 'boom'], 500)
            ->push(['id' => 7, 'name' => 'Luis', 'email' => 'luis@example.com']),
    ]);

    try {
        app(UserServiceClient::class)->authenticate('token');
    } catch (UserServiceErrorException) {
        // primer 500: abre el circuito
    }

    $this->withSession([TokenSession::SESSION_KEY => 'token'])
        ->get('/tasks')
        ->assertStatus(503);

    Http::assertSentCount(1);
});

/*
|--------------------------------------------------------------------------
| Verificación local de JWT (b0.7)
|--------------------------------------------------------------------------
|
| El IdP firma con RS256 y publica su clave pública en la JWKS. Estas
| pruebas llevan la otra mitad del pacto: que el RP verifica en local,
| con la JWKS cacheada, sin llamar a /api/user por cada request — y que
| la rotación de claves (kid nuevo) hace refetch sin despliegue.
|
*/

function makeTestKeyAndJwk(string $kid): array
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($key, $private);

    $details = openssl_pkey_get_details($key);

    $b64url = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    $jwk = [
        'kty' => 'RSA',
        'use' => 'sig',
        'alg' => 'RS256',
        'kid' => $kid,
        'n' => $b64url($details['rsa']['n']),
        'e' => $b64url($details['rsa']['e']),
    ];

    return [$private, $jwk];
}

function signTestJwt(string $private, string $kid): string
{
    return JWT::encode([
        'iss' => 'user-service',
        'sub' => '7',
        'name' => 'Ana Torres',
        'email' => 'ana@example.com',
        'iat' => time(),
        'exp' => time() + 15 * 60,
        'jti' => bin2hex(random_bytes(16)),
        'device_name' => 'pest',
    ], $private, 'RS256', $kid);
}

it('verifies a JWT locally against the cached JWKS', function () {
    [$private, $jwk] = makeTestKeyAndJwk('k-test');

    Http::fake(['*/api/jwks.json' => Http::response(['keys' => [$jwk]]), '*/api/user' => Http::response([], 500)]);

    expect(app(JwtVerifier::class)->verify(signTestJwt($private, 'k-test')))->toBe([
        'id' => 7,
        'name' => 'Ana Torres',
        'email' => 'ana@example.com',
    ]);

    // Lo único que tocó la red para poder verificar TODO el futuro inmediato
    // fue la JWKS (se queda en caché): cero llamadas a /api/user.
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/jwks.json'));
});

it('authenticates any middleware request with a JWT and never touches /api/user', function () {
    [$private, $jwk] = makeTestKeyAndJwk('rot');

    Http::fake([
        '*/api/jwks.json' => Http::response(['keys' => [$jwk]]),
        '*/api/user' => Http::response([], 500),
    ]);

    $this->withToken(signTestJwt($private, 'rot'))
        ->getJson('/api/tasks')
        ->assertOk();

    // Si una sola verificación hubiera ido a /api/user, la suite se habría
    // roto: el fake lo devuelve 500. Se sirvió la JWKS, y nada más.
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/jwks.json'));

    // La firma de vida de la JWKS: el segundo request ni siquiera vuelve a
    // preguntarla, porque el ttl la guarda en caché.
    $this->withToken(signTestJwt($private, 'rot'))
        ->getJson('/api/tasks')
        ->assertOk();

    Http::assertSentCount(1);
});

it('rejects an expired JWT locally', function () {
    [$private, $jwk] = makeTestKeyAndJwk('exp');

    Http::fake(['*/api/jwks.json' => Http::response(['keys' => [$jwk]]), '*/api/user' => Http::response([], 500)]);

    $expired = JWT::encode([
        'iss' => 'user-service',
        'sub' => '7',
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'iat' => time() - 16 * 60,
        'exp' => time() - 60,
    ], $private, 'RS256', 'exp');

    expect(app(JwtVerifier::class)->verify($expired))->toBeNull();
});

it('refetches the JWKS when an unknown kid arrives, and recovers without deploy', function () {
    [$privateA, $jwkA] = makeTestKeyAndJwk('key-you-have');
    [$privateB, $jwkB] = makeTestKeyAndJwk('new-key-after-rotation');

    // Primera respuesta: solo la clave vieja. Tras el refetch forzado por
    // un kid desconocido, ya contiene la nueva.
    Http::fake([
        '*/api/jwks.json' => Http::sequence()
            ->push(['keys' => [$jwkA]])
            ->push(['keys' => [$jwkA, $jwkB]]),
        '*/api/user' => Http::response([], 500),
    ]);

    $usuario = [
        'id' => 7,
        'name' => 'Ana Torres',
        'email' => 'ana@example.com',
    ];

    // kid conocido: fetch 1 y listo.
    expect(app(JwtVerifier::class)->verify(signTestJwt($privateA, 'key-you-have')))->toBe($usuario);

    // kid desconocido: la verificación fuerza el refetch (fetch 2), la
    // JWKS ya contiene la clave nueva y el token queda verificado en el
    // mismo paso. La rotación de claves del IdP no requirió tocar los RPs.
    expect(app(JwtVerifier::class)->verify(signTestJwt($privateB, 'new-key-after-rotation')))->toBe($usuario);

    Http::assertSentCount(2);
});

it('rejects a validly signed JWT with a foreign issuer claim', function () {
    [$private, $jwk] = makeTestKeyAndJwk('impostor');

    Http::fake(['*/api/jwks.json' => Http::response(['keys' => [$jwk]]), '*/api/user' => Http::response([], 500)]);

    $forged = JWT::encode([
        'iss' => 'otro-identificador',
        'sub' => '7',
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'iat' => time(),
        'exp' => time() + 15 * 60,
    ], $private, 'RS256', 'impostor');

    expect(app(JwtVerifier::class)->verify($forged))->toBeNull();
});
