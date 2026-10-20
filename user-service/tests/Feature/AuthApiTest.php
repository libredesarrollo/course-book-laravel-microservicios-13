<?php

use App\Models\ApiToken;
use App\Models\User;

function issueTokenFor(User $user): string
{
    return ApiToken::issueFor($user, 'test', now()->addHour())['token'];
}

it('issues a token for valid credentials', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $response = $this->postJson('/api/tokens', [
        'email' => 'ana@example.com',
        'password' => 'password',
        'device_name' => 'pest',
    ])->assertCreated();

    expect($response->json('token'))->toBeString()->toHaveLength(64)
        ->and($response->json('token_type'))->toBe('Bearer')
        ->and($response->json('user.id'))->toBe($user->id);
});

it('stores only the hash of the token, never the plain text', function () {
    $user = User::factory()->create();

    $token = issueTokenFor($user);

    expect(ApiToken::query()->where('user_id', $user->id)->first()->token_hash)
        ->toBe(hash('sha256', $token))
        ->and(ApiToken::query()->get()->pluck('token_hash')->all())
        ->not->toContain($token);
});

it('rejects a wrong password with a generic message', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $this->postJson('/api/tokens', ['email' => 'ana@example.com', 'password' => 'mala'])
        ->assertUnauthorized()
        ->assertJson(['error' => 'Credenciales inválidas']);
});

it('gives the same answer for an unknown email as for a wrong password', function () {
    $unknown = $this->postJson('/api/tokens', ['email' => 'nadie@example.com', 'password' => 'x']);

    User::factory()->create(['email' => 'ana@example.com']);
    $wrongPassword = $this->postJson('/api/tokens', ['email' => 'ana@example.com', 'password' => 'x']);

    expect($unknown->status())->toBe(401)
        ->and($wrongPassword->status())->toBe(401)
        ->and($unknown->json())->toBe($wrongPassword->json());
});

it('validates the login payload', function () {
    $this->postJson('/api/tokens', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

it('resolves the user behind a valid token', function () {
    $user = User::factory()->create(['name' => 'Ana Torres']);
    $token = issueTokenFor($user);

    $this->getJson('/api/user')
        ->assertUnauthorized(); // sin cabecera

    $this->withToken($token)
        ->getJson('/api/user')
        ->assertOk()
        ->assertExactJson([
            'id' => $user->id,
            'name' => 'Ana Torres',
            'email' => $user->email,
        ]);
});

it('rejects an unknown token', function () {
    $this->withToken(str_repeat('a', 64))
        ->getJson('/api/user')
        ->assertUnauthorized();
});

it('rejects an expired token', function () {
    $user = User::factory()->create();
    $issued = ApiToken::issueFor($user, 'test', now()->subMinute())['model'];
    $issued->update(['expires_at' => now()->subMinute()]);

    $this->withToken($issued->token_hash)
        ->getJson('/api/user')
        ->assertUnauthorized();
});

it('never leaks the password hash', function () {
    $user = User::factory()->create();

    $this->withToken(issueTokenFor($user))->getJson('/api/user')
        ->assertJsonMissingPath('password');
});

it('revokes the token that was used', function () {
    $user = User::factory()->create();
    $token = issueTokenFor($user);

    $this->withToken($token)->deleteJson('/api/tokens')->assertNoContent();

    expect(ApiToken::query()->count())->toBe(0);

    $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
});

it('stamps last_used_at when the token is used', function () {
    $user = User::factory()->create();
    $issued = ApiToken::issueFor($user, 'test', now()->addHour());

    expect($issued['model']->last_used_at)->toBeNull();

    $this->withToken($issued['token'])->getJson('/api/user')->assertOk();

    expect($issued['model']->fresh()->last_used_at)->not->toBeNull();
});
