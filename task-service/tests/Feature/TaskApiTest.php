<?php

use App\Models\Task;

/*
| La API no tiene tabla de usuarios: cada request se autentica preguntando al
| user-service. Estos tests comprueban las dos caras de esa moneda: que un
| token válido abre la puerta y que sin token no se entra.
*/

it('rejects every endpoint without a token', function (string $method, string $uri) {
    Http::fake();

    test()->json($method, $uri)->assertUnauthorized();

    Http::assertNothingSent();
})->with([
    'index' => ['GET', '/api/tasks'],
    'store' => ['POST', '/api/tasks'],
    'show' => ['GET', '/api/tasks/1'],
    'update' => ['PUT', '/api/tasks/1'],
    'destroy' => ['DELETE', '/api/tasks/1'],
]);

it('rejects a token the user-service does not recognise', function () {
    Http::fake(['*/api/user' => Http::response(['error' => 'Unauthenticated.'], 401)]);

    $this->withToken('token-falso')->getJson('/api/tasks')->assertUnauthorized();
});

it('returns 503 when the user-service is unreachable', function () {
    fakeUserServiceDown();

    $this->withToken('test-token')->getJson('/api/tasks')->assertStatus(503);
});

it('asks the user-service who the caller is, on every request', function () {
    fakeUserService(['id' => 7, 'name' => 'Luis Ramirez', 'email' => 'luis@example.com']);

    $this->withToken('test-token')->getJson('/api/tasks')->assertOk();

    Http::assertSent(
        fn ($request) => $request->url() === config('user-service-client.url').'/api/user'
            && $request->hasHeader('Authorization', 'Bearer test-token')
    );
});

it('creates a task owned by the caller', function () {
    fakeUserService(['id' => 7, 'name' => 'Luis Ramirez', 'email' => 'luis@example.com']);

    $this->withToken('test-token')
        ->postJson('/api/tasks', ['title' => 'Comprar el almuerzo'])
        ->assertCreated()
        ->assertJson([
            'title' => 'Comprar el almuerzo',
            'user_id' => 7,
            'status' => Task::STATUS_PENDING,
        ]);

    expect(Task::query()->first()->user_id)->toBe(7);
});

it('ignores any user_id the client tries to inject', function () {
    fakeUserService(['id' => 7, 'name' => 'Luis Ramirez', 'email' => 'luis@example.com']);

    $this->withToken('test-token')
        ->postJson('/api/tasks', ['title' => 'Tarea de Ana', 'user_id' => 1])
        ->assertCreated()
        ->assertJson(['user_id' => 7]);
});

it('lists only the tasks of the caller', function () {
    $me = fakeUserService(['id' => 1, 'name' => 'Ana', 'email' => 'ana@example.com']);

    Task::factory()->create(['title' => 'Mía', 'user_id' => $me['id']]);
    Task::factory()->create(['title' => 'De Luis', 'user_id' => 2]);

    $response = $this->withToken('test-token')->getJson('/api/tasks')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.title'))->toBe('Mía');
});

it('refuses to show a task that belongs to someone else', function () {
    fakeUserService(['id' => 1, 'name' => 'Ana', 'email' => 'ana@example.com']);

    $task = Task::factory()->create(['user_id' => 2]);

    $this->withToken('test-token')->getJson("/api/tasks/{$task->id}")->assertForbidden();
});

it('refuses to update a task that belongs to someone else', function () {
    fakeUserService(['id' => 1, 'name' => 'Ana', 'email' => 'ana@example.com']);

    $task = Task::factory()->create(['title' => 'De Luis', 'user_id' => 2]);

    $this->withToken('test-token')
        ->putJson("/api/tasks/{$task->id}", ['title' => 'Secuestrada'])
        ->assertForbidden();

    expect($task->fresh()->title)->toBe('De Luis');
});

it('refuses to delete a task that belongs to someone else', function () {
    fakeUserService(['id' => 1, 'name' => 'Ana', 'email' => 'ana@example.com']);

    $task = Task::factory()->create(['user_id' => 2]);

    $this->withToken('test-token')->deleteJson("/api/tasks/{$task->id}")->assertForbidden();

    expect(Task::query()->count())->toBe(1);
});

it('updates and deletes its own task', function () {
    fakeUserService(['id' => 5, 'name' => 'Ana', 'email' => 'ana@example.com']);

    $task = Task::factory()->create(['title' => 'Título viejo', 'user_id' => 5]);

    $this->withToken('test-token')
        ->putJson("/api/tasks/{$task->id}", [
            'title' => 'Título nuevo',
            'status' => Task::STATUS_COMPLETED,
        ])
        ->assertOk()
        ->assertJson(['title' => 'Título nuevo', 'status' => Task::STATUS_COMPLETED]);

    $this->withToken('test-token')->deleteJson("/api/tasks/{$task->id}")->assertNoContent();

    expect(Task::query()->count())->toBe(0);
});

it('validates the payload before touching anything', function () {
    fakeUserService();

    $this->withToken('test-token')
        ->postJson('/api/tasks', ['status' => 'inventado'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title', 'status']);
});

it('rejects a status outside the allowed set', function () {
    fakeUserService();

    $this->withToken('test-token')
        ->postJson('/api/tasks', ['title' => 'Tarea', 'status' => 'volando'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});
