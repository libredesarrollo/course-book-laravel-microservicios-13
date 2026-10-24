<?php

use Demo\Microservices\UserServiceClient\TokenSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('redirects anonymous users to login on facturas', function () {
    $response = $this->get('/facturas');

    $response->assertStatus(302);
    $response->assertRedirect('/login');
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

it('can log in via token session flow and reach facturas', function () {
    $token = 'tok-'.Str::random(40);

    Http::fake([
        '*/api/tokens' => Http::response([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => now()->addHour()->toIso8601String(),
            'user' => ['id' => 1, 'name' => 'Ana Torres', 'email' => 'ana.torres@example.com'],
        ], 200),
        '*/api/user' => Http::response(['id' => 1, 'name' => 'Ana Torres', 'email' => 'ana.torres@example.com'], 200),
    ]);

    $this->from('/login')
        ->post('/login', [
            'email' => 'ana.torres@example.com',
            'password' => 'password',
        ])
        ->assertStatus(302)
        ->assertRedirect('/facturas');

    $this->get('/facturas')
        ->assertStatus(200)
        ->assertSee('Ana Torres');
});

it('shows errors on invalid credentials', function () {
    Http::fake([
        '*/api/tokens' => Http::response(['message' => 'Invalid credentials'], 401),
    ]);

    $this->from('/login')
        ->post('/login', [
            'email' => 'ana.torres@example.com',
            'password' => 'bad',
        ])
        ->assertStatus(302)
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');
});

it('closes session on logout', function () {
    $token = 'tok-'.Str::random(40);
    Http::fake([
        '*/api/user' => Http::response(['id' => 1, 'name' => 'Ana Torres', 'email' => 'ana.torres@example.com'], 200),
        '*/api/tokens' => Http::response([], 204),
    ]);

    $this->withSession([TokenSession::SESSION_KEY => $token])
        ->post('/logout')
        ->assertStatus(302)
        ->assertRedirect('/login');

    $this->get('/facturas')
        ->assertStatus(302)
        ->assertRedirect('/login');
});
