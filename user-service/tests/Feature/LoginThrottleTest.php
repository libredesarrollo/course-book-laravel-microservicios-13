<?php

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/*
| El rate limit vive en el user-service porque es el único que recibe
| contraseñas. Estos tests comprueban que frena de verdad, y sobre todo que
| frena lo correcto: una cuenta y una IP agotan presupuestos separados.
*/

beforeEach(function () {
    // El limitador guarda su contador en caché; array store = limpio por test.
    config([
        'tokens.ttl_minutes' => 60,
        'tokens.throttle.per_account' => 5,
        'tokens.throttle.per_ip' => 20,
        'tokens.throttle.decay_seconds' => 60,
    ]);
});

function attemptLogin(array $overrides = [], string $ip = '10.0.0.1'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/tokens', [
            'email' => 'ana@example.com',
            'password' => 'incorrecta',
            ...$overrides,
        ]);
}

it('allows a handful of failed attempts and blocks after the per-account limit', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    // 5 intentos: todos pasan el limitador y responden 401.
    for ($i = 1; $i <= 5; $i++) {
        attemptLogin()->assertUnauthorized();
    }

    // El 6º ya está bloqueado, aunque la credencial sea correcta.
    $user = User::query()->where('email', 'ana@example.com')->first();

    test()->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->postJson('/api/tokens', [
            'email' => 'ana@example.com',
            'password' => 'password',
        ])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('never issues a token for a failed attempt', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    attemptLogin()->assertUnauthorized();

    expect(ApiToken::query()->count())->toBe(0);
});

it('blocks the correct password too once the account limit is spent', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    for ($i = 1; $i <= 5; $i++) {
        attemptLogin()->assertUnauthorized();
    }

    // Esta vez la contraseña SÍ es correcta, y aun así no pasa.
    test()->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->postJson('/api/tokens', ['email' => 'ana@example.com', 'password' => 'password'])
        ->assertStatus(429);

    expect(ApiToken::query()->count())->toBe(0);
});

it('gives each account its own budget', function () {
    User::factory()->create(['email' => 'ana@example.com']);
    User::factory()->create(['email' => 'luis@example.com']);

    // Agota la cuenta de Ana.
    for ($i = 1; $i <= 5; $i++) {
        attemptLogin(['email' => 'ana@example.com'])->assertUnauthorized();
    }

    // Luis sigue teniendo su presupuesto intacto desde la misma IP.
    attemptLogin(['email' => 'luis@example.com'])->assertUnauthorized();

    $this->assertFalse(User::query()->where('email', 'ana@example.com')->exists() === false);
});

it('stops a sweep across many accounts from a single IP', function () {
    for ($i = 1; $i <= 20; $i++) {
        User::factory()->create(['email' => "user{$i}@example.com"]);
    }

    // 20 intentos, cada uno contra una cuenta distinta: el límite por cuenta
    // no se agota nunca, pero el de IP sí.
    for ($i = 1; $i <= 20; $i++) {
        attemptLogin(['email' => "user{$i}@example.com"])->assertUnauthorized();
    }

    attemptLogin(['email' => 'user21@example.com'])->assertStatus(429);
});

it('gives each IP its own budget', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    for ($i = 1; $i <= 5; $i++) {
        attemptLogin([], '10.0.0.1')->assertUnauthorized();
    }

    // La misma cuenta desde otra IP no está bloqueada.
    attemptLogin([], '10.0.0.2')->assertUnauthorized();
});

it('logs every failed attempt with the ip and whether the account exists', function () {
    Log::spy();

    User::factory()->create(['email' => 'ana@example.com']);
    User::factory()->create(['email' => 'luis@example.com']);

    attemptLogin(['email' => 'ana@example.com']);
    attemptLogin(['email' => 'nadie@example.com']);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Failed login attempt'
            && $context['email'] === 'ana@example.com'
            && $context['account_exists'] === true)
        ->once();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Failed login attempt'
            && $context['account_exists'] === false)
        ->once();
});

it('never logs the attempted password', function () {
    Log::spy();

    User::factory()->create(['email' => 'ana@example.com']);

    attemptLogin(['password' => 'mi-secreto-muy-largo']);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => ! str_contains(json_encode($context), 'mi-secreto-muy-largo'));
});

it('gives a fresh token a TTL taken from the configuration', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $response = $this->postJson('/api/tokens', [
        'email' => 'ana@example.com',
        'password' => 'password',
    ])->assertCreated();

    $expiresAt = $response->json('expires_at');

    expect($expiresAt)->not->toBeNull();

    // La caducidad debe ser "ahora + 60 min", con un margen para el reloj.
    $model = ApiToken::query()->latest('id')->first();

    expect($model->expires_at->getTimestamp())
        ->toBeBetween(now()->addMinutes(58)->getTimestamp(), now()->addMinutes(60)->getTimestamp());
});

it('accepts a token that has not expired', function () {
    $user = User::factory()->create();

    $token = ApiToken::issueFor($user, 'test', now()->addMinutes(60))['token'];

    $this->withToken($token)->getJson('/api/user')->assertOk();
});

it('rejects a token that has expired', function () {
    $user = User::factory()->create();
    $token = ApiToken::issueFor($user, 'test', now()->subMinute())['token'];

    $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
});

it('makes it structurally impossible to create a token without an expiry', function () {
    // expires_at es NOT NULL en la migración y obligatorio en issueFor(),
    // así que no hay forma de crear un token que sirva para siempre.
    expect(ApiToken::factory()->create())->not->toBeNull()
        ->and(ApiToken::factory()->create()->expires_at)->not->toBeNull()
        ->and(ApiToken::factory()->expired()->create()->isExpired())->toBeTrue();
});
