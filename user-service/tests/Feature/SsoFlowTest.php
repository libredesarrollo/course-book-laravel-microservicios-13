<?php

use App\Models\ApiToken;
use App\Models\SsoTicket;
use App\Models\User;

/**
 * Crea un ticket SSO ya emitido, con el hash de un código conocido.
 */
function issueSsoTicket(User $user, string $code, string $client = 'task', ?string $redirectUri = null): SsoTicket
{
    return SsoTicket::query()->create([
        'code_hash' => hash('sha256', $code),
        'user_id' => $user->id,
        'client' => $client,
        'expires_at' => now()->addMinute(),
        'used_at' => null,
    ]);
}

/*
|--------------------------------------------------------------------------
| El login del IdP
|--------------------------------------------------------------------------
*/

it('shows the login form to anonymous visitors', function () {
    $this->get(route('login'))->assertOk()->assertSee('Iniciar sesión', escape: false);
});

it('redirects authenticated visitors away from the login form', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('home'));
});

it('signs in and redirects back to the requested client with a code', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $response = $this->post('/login', [
        'email' => 'ana@example.com',
        'password' => 'password',
        'client' => 'task',
        'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
    ]);

    $location = $response->headers->get('Location');

    expect($location)->toStartWith('http://127.0.0.1:8001/sso/callback?code=')
        ->and(SsoTicket::query()->where('user_id', $user->id)->count())->toBe(1);

    $this->assertAuthenticatedAs($user);
});

it('does not store the plain text code', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $this->post('/login', [
        'email' => 'ana@example.com',
        'password' => 'password',
        'client' => 'task',
        'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
    ]);

    $code = SsoTicket::query()->first()->code_hash;

    expect($code)->toHaveLength(64)
        ->and(SsoTicket::query()->first()->getAttributes())->not->toContain('code');
});

it('rejects invalid credentials without issuing a code', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $this->from(route('login'))
        ->post('/login', [
            'email' => 'ana@example.com',
            'password' => 'incorrecta',
            'client' => 'task',
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    expect(SsoTicket::query()->count())->toBe(0);
});

it('signs in without a client and lands on the home page', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $this->post('/login', [
        'email' => 'ana@example.com',
        'password' => 'password',
    ])->assertRedirect(route('home'));

    expect(SsoTicket::query()->count())->toBe(0);
});

it('logs out and returns to the requested redirect uri', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('logout', ['redirect_uri' => 'http://127.0.0.1:8001']))
        ->assertRedirect('http://127.0.0.1:8001');

    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| Autorización SSO
|--------------------------------------------------------------------------
*/

it('sends an anonymous authorize request to the login page', function () {
    $this->get(route('sso.authorize', [
        'client' => 'task',
        'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
    ]))->assertRedirect(route('login', [
        'client' => 'task',
        'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
    ]));
});

it('issues a code for an authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('sso.authorize', [
        'client' => 'task',
        'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
    ]));

    expect($response->headers->get('Location'))
        ->toStartWith('http://127.0.0.1:8001/sso/callback?code=');

    expect(SsoTicket::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('rejects an unknown sso client', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('sso.authorize', [
            'client' => 'desconocido',
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
        ]))
        ->assertStatus(400);

    expect(SsoTicket::query()->count())->toBe(0);
});

it('rejects a redirect uri that is not whitelisted for the client', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('sso.authorize', [
            'client' => 'task',
            'redirect_uri' => 'http://evil.example/callback',
        ]))
        ->assertStatus(400);

    expect(SsoTicket::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Canje del código
|--------------------------------------------------------------------------
*/

it('exchanges a valid code for a token', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    issueSsoTicket($user, 'codigo-valido');

    $response = $this->postJson('/api/sso/exchange', [
        'code' => 'codigo-valido',
        'client' => 'task',
    ])->assertCreated();

    expect($response->json('token'))->toBeString()->toHaveLength(64)
        ->and($response->json('token_type'))->toBe('Bearer')
        ->and($response->json('user.email'))->toBe('ana@example.com');

    expect(SsoTicket::query()->first()->isUsed())->toBeTrue()
        ->and(ApiToken::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('marks the code as used so it cannot be exchanged twice', function () {
    issueSsoTicket(User::factory()->create(), 'codigo-un-solo-uso');

    $payload = ['code' => 'codigo-un-solo-uso', 'client' => 'task'];

    $this->postJson('/api/sso/exchange', $payload)->assertCreated();
    $this->postJson('/api/sso/exchange', $payload)->assertStatus(400);
});

it('rejects an expired code', function () {
    $ticket = issueSsoTicket(User::factory()->create(), 'codigo-caducado');
    $ticket->update(['expires_at' => now()->subMinute()]);

    $this->postJson('/api/sso/exchange', [
        'code' => 'codigo-caducado',
        'client' => 'task',
    ])->assertStatus(400);
});

it('rejects a code issued for another client', function () {
    issueSsoTicket(User::factory()->create(), 'codigo-de-task', 'task');

    $this->postJson('/api/sso/exchange', [
        'code' => 'codigo-de-task',
        'client' => 'billing',
    ])->assertStatus(400);
});

it('rejects an unknown code', function () {
    $this->postJson('/api/sso/exchange', [
        'code' => 'no-existe',
        'client' => 'task',
    ])->assertStatus(400);
});

it('validates the exchange payload', function () {
    $this->postJson('/api/sso/exchange', [])->assertUnprocessable();
});

it('invalidates the previous pending code when a new one is issued', function () {
    $user = User::factory()->create();

    $first = $this->actingAs($user)->get(route('sso.authorize', [
        'client' => 'task',
        'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
    ]));

    $this->actingAs($user)->get(route('sso.authorize', [
        'client' => 'task',
        'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
    ]));

    expect(SsoTicket::query()->count())->toBe(1);

    $firstCode = urldecode(substr($first->headers->get('Location'), strpos($first->headers->get('Location'), 'code=') + 5));

    $this->postJson('/api/sso/exchange', [
        'code' => $firstCode,
        'client' => 'task',
    ])->assertStatus(400);
});
