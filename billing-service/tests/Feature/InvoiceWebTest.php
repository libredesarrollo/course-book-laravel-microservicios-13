<?php

use Demo\Microservices\UserServiceClient\TokenSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('redirects anonymous users to the sso start route on facturas', function () {
    $response = $this->get('/facturas');

    $response->assertStatus(302);
    $response->assertRedirect(route('sso.start'));
});

it('starts the sso flow against the user-service', function () {
    $this->get(route('sso.start'))
        ->assertRedirect(config('user-service-client.url').'/authorize?'.http_build_query([
            'client' => config('user-service-client.client'),
            'redirect_uri' => url('/sso/callback'),
        ]));
});

it('exchanges the sso code and stores the returned token', function () {
    Http::fake([
        '*/api/sso/exchange' => Http::response([
            'token' => 'tok-'.Str::random(40),
            'token_type' => 'Bearer',
            'expires_at' => now()->addHour()->toIso8601String(),
            'user' => ['id' => 1, 'name' => 'Ana Torres', 'email' => 'ana.torres@example.com'],
        ], 201),
    ]);

    $this->get(route('sso.callback', ['code' => 'codigo-ss0']))
        ->assertRedirect('/')
        ->assertSessionHas(TokenSession::SESSION_KEY);

    Http::assertSent(fn ($request) => $request->url() === config('user-service-client.url').'/api/sso/exchange'
        && $request['code'] === 'codigo-ss0'
        && $request['client'] === config('user-service-client.client')
    );
});

it('rejects an sso code the user-service does not accept', function () {
    Http::fake(['*/api/sso/exchange' => Http::response(['error' => 'Código inválido'], 422)]);

    $this->get(route('sso.callback', ['code' => 'codigo-caducado']))
        ->assertUnauthorized()
        ->assertSessionMissing(TokenSession::SESSION_KEY);
});

it('shows invoices and user name when authenticated', function () {
    $token = 'tok-'.Str::random(40);
    Http::fake([
        '*/api/user' => Http::response(['id' => 1, 'name' => 'Ana Torres', 'email' => 'ana.torres@example.com'], 200),
    ]);

    $this->withSession([TokenSession::SESSION_KEY => $token])
        ->get('/facturas')
        ->assertStatus(200)
        ->assertSee('Ana Torres')
        ->assertSee('Mis facturas')
        ->assertSee('#101')
        ->assertSee('Pagada')
        ->assertSee('#102')
        ->assertSee('Pendiente');
});

it('reaches facturas with an sso session', function () {
    $token = 'tok-'.Str::random(40);

    Http::fake([
        '*/api/sso/exchange' => Http::response([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => now()->addHour()->toIso8601String(),
            'user' => ['id' => 1, 'name' => 'Ana Torres', 'email' => 'ana.torres@example.com'],
        ], 201),
        '*/api/user' => Http::response(['id' => 1, 'name' => 'Ana Torres', 'email' => 'ana.torres@example.com'], 200),
    ]);

    $this->get(route('sso.callback', ['code' => 'codigo-ss0']))->assertRedirect('/');

    $this->get('/facturas')
        ->assertStatus(200)
        ->assertSee('Ana Torres');
});

it('closes the local session and hands the logout to the user-service', function () {
    $token = 'tok-'.Str::random(40);
    Http::fake([
        '*/api/tokens' => Http::response([], 204),
    ]);

    $this->withSession([TokenSession::SESSION_KEY => $token])
        ->post('/logout')
        ->assertStatus(302)
        ->assertRedirect(config('user-service-client.url').'/logout?'.http_build_query([
            'redirect_uri' => url('/'),
        ]))
        ->assertSessionMissing(TokenSession::SESSION_KEY);
});
