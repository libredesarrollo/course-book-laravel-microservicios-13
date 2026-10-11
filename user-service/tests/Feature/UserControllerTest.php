<?php

use App\Models\User;

it('returns the user as json with status 200 when the user exists', function () {
    $user = User::factory()->create([
        'name' => 'Ana Torres',
        'email' => 'ana.torres@example.com',
    ]);

    $this->getJson("/api/users/{$user->id}")
        ->assertOk()
        ->assertJson([
            'id' => $user->id,
            'name' => 'Ana Torres',
            'email' => 'ana.torres@example.com',
        ]);
});

it('returns a 404 json error when the user does not exist', function () {
    $this->getJson('/api/users/999999')
        ->assertNotFound()
        ->assertExactJson(['error' => 'Usuario no encontrado']);
});

it('returns a paginated list of users', function () {
    User::factory()->count(3)->create();

    $response = $this->getJson('/api/users')->assertOk();

    expect($response->json('data'))->toHaveCount(3)
        ->and($response->json('total'))->toBe(3);
});
