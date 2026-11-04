<?php

use App\Models\ApiToken;
use App\Models\User;
use App\Services\Jwt\JwtKeys;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| JWT stateless (b0.7)
|--------------------------------------------------------------------------
|
| El IdP firma RS256 y publica su clave pública en /api/jwks.json. Los
| relying parties verifican en local; estas pruebas comprueban el otro
| lado del pacto: que lo firmado se valida, que lo expirado se rechaza,
| y que lo que el logout NO puede hacer (matar un JWT) sea explícito.
|
*/

beforeEach(function () {
    // La suite corre en cualquier entorno; sin par de claves, el modo JWT
    // no tiene con qué firmar. Generar aquí es idempotente.
    if (! is_file(config('jwt.public_key'))) {
        $this->artisan('user-service:jwt-keys');
    }
});

function loginResponse(): TestResponse
{
    return test()->postJson('/api/tokens', [
        'email' => 'ana@example.com',
        'password' => 'password',
    ]);
}

function jwtPayload(string $token): array
{
    return json_decode(base64_decode(explode('.', $token)[1]), true);
}

it('publishes the public key as a JWKS document', function () {
    $response = $this->getJson('/api/jwks.json')->assertOk();

    $key = $response->json('keys.0');

    expect($key['kty'])->toBe('RSA')
        ->and($key['alg'])->toBe('RS256')
        ->and($key['use'])->toBe('sig')
        ->and($key['kid'])->toBe(app(JwtKeys::class)->kid())
        ->and($key['kid'])->toHaveLength(16)
        ->and($key['n'])->toBeString()
        ->and($key['e'])->toBeString();
});

it('emits a signed JWT with the documented claims on login', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $token = loginResponse()->assertCreated()->json('token');

    $claims = jwtPayload($token);

    expect($claims['iss'])->toBe('user-service')
        ->and((int) $claims['sub'])->toBe($user->id)
        ->and($claims['email'])->toBe($user->email)
        ->and($claims['name'])->toBe($user->name)
        ->and($claims['exp'])->toBeGreaterThan(now()->getTimestamp());

    // El header lleva el kid: es lo que permite al RP cachear la JWKS y
    // refetchearla solo cuando aparezca un kid desconocido.
    $header = json_decode(base64_decode(explode('.', $token)[0]), true);

    expect($header['kid'])->toBe(app(JwtKeys::class)->kid())
        ->and($header['alg'])->toBe('RS256');
});

it('authorizes GET /api/user with the JWT, locally', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $token = loginResponse()->json('token');

    $this->withToken($token)->getJson('/api/user')
        ->assertOk()
        ->assertJson(['id' => $user->id, 'name' => $user->name, 'email' => $user->email]);

    // Lo que hace el stateless: cero filas de tokens donde buscar.
    expect(ApiToken::query()->count())->toBe(0);
});

it('rejects a tampered JWT', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $token = loginResponse()->json('token');

    $pieces = explode('.', $token);
    $tampered = $pieces[0].'.tampered-payload.'.$pieces[2];

    $this->withToken($tampered)->getJson('/api/user')->assertUnauthorized();
});

it('rejects a JWT signed with a different key', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    // Re-firmamos los claims con la MISMA lib y otra clave: la firma no
    // va a coincidir con la pública publicada, y ahí termina la impersonación.
    $token = loginResponse()->json('token');
    $claims = jwtPayload($token);
    $claims['sub'] = '999999';

    $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($other, $otherPrivatePem);

    $forged = JWT::encode($claims, $otherPrivatePem, 'RS256', 'forjada');

    $this->withToken($forged)->getJson('/api/user')->assertUnauthorized();
});

it('expires JWTs, no matter how good the signature', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $token = loginResponse()->json('token');

    // El reloj del verificador es time(), no Carbon: se congela con el
    // reloj estático de la propia librería, el mismo truco de un RP
    // para probar expiraciones sin esperar el TTL.
    try {
        JWT::$timestamp = time() + (21 * 60); // TTL por defecto: 15 minutos

        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    } finally {
        JWT::$timestamp = null;
    }
});

it('treats JWT logout as a no-op and says so honestly', function () {
    Log::spy();

    User::factory()->create(['email' => 'ana@example.com']);

    $token = loginResponse()->json('token');

    $this->withToken($token)
        ->deleteJson('/api/tokens')
        ->assertNoContent();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'no-op'));

    // La verdad incómoda del stateless: el token sigue siendo válido porque
    // nadie lo puede revocar. La mitigación es el TTL corto (JWT_TTL_MINUTES).
    $this->withToken($token)->getJson('/api/user')->assertOk();
});

it('still authenticates legacy opaque tokens next to JWTs', function () {
    $user = User::factory()->create();

    $opaque = ApiToken::issueFor($user, 'legacy', now()->addMinutes(60))['token'];

    $this->withToken($opaque)->getJson('/api/user')
        ->assertOk()
        ->assertJson(['id' => $user->id]);
});

it('revokes legacy opaque tokens on logout, unlike JWTs', function () {
    $user = User::factory()->create();

    $opaque = ApiToken::issueFor($user, 'legacy', now()->addMinutes(60))['token'];

    $this->withToken($opaque)->deleteJson('/api/tokens')->assertNoContent();

    $this->withToken($opaque)->getJson('/api/user')->assertUnauthorized();
});
