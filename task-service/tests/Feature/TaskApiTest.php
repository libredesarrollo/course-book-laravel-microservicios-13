<?php

use App\Models\Task;
use Illuminate\Support\Facades\Http;

it('creates a task when the user-service confirms that the user exists', function () {
    Http::fake([
        '*' => Http::response(['id' => 1, 'name' => 'Ana Torres', 'email' => 'ana.torres@example.com'], 200),
    ]);

    $this->postJson('/api/tasks', [
        'title' => 'Comprar el almuerzo',
        'user_id' => 1,
    ])
        ->assertCreated()
        ->assertJson([
            'title' => 'Comprar el almuerzo',
            'user_id' => 1,
            'status' => Task::STATUS_PENDING,
        ]);

    expect(Task::query()->count())->toBe(1);

    Http::assertSent(
        fn ($request) => $request->url() === config('services.user_service.url').'/api/users/1'
    );
});

it('does not create the task when the user-service answers 404', function () {
    Http::fake([
        '*' => Http::response(['error' => 'Usuario no encontrado'], 404),
    ]);

    $this->postJson('/api/tasks', [
        'title' => 'Tarea de un usuario inexistente',
        'user_id' => 42,
    ])
        ->assertStatus(422)
        ->assertJson(['error' => 'El usuario no existe en el sistema']);

    expect(Task::query()->count())->toBe(0);
});

it('does not create the task when the user-service answers with a server error', function () {
    Http::fake([
        '*' => Http::response(['message' => 'Server Error'], 500),
    ]);

    $this->postJson('/api/tasks', [
        'title' => 'Tarea con user-service roto',
        'user_id' => 1,
    ])
        ->assertStatus(502);

    expect(Task::query()->count())->toBe(0);
});

it('returns 503 when the user-service is unreachable', function () {
    Http::fake([
        '*' => Http::failedConnection(),
    ]);

    $this->postJson('/api/tasks', [
        'title' => 'Tarea con el user-service apagado',
        'user_id' => 1,
    ])
        ->assertStatus(503);

    expect(Task::query()->count())->toBe(0);
});

it('validates the request payload before calling the user-service', function () {
    Http::fake();

    $this->postJson('/api/tasks', ['title' => 'Sin user_id'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('user_id');

    Http::assertNothingSent();
});

it('lists the tasks in a paginated json response', function () {
    Task::factory()->count(3)->create();

    $response = $this->getJson('/api/tasks')->assertOk();

    expect($response->json('data'))->toHaveCount(3)
        ->and($response->json('total'))->toBe(3);
});

it('shows a single task', function () {
    $task = Task::factory()->create(['title' => 'Tarea puntual']);

    $this->getJson("/api/tasks/{$task->id}")
        ->assertOk()
        ->assertJson(['id' => $task->id, 'title' => 'Tarea puntual']);
});

it('updates a task without calling the user-service when the user_id does not change', function () {
    $task = Task::factory()->create(['title' => 'Título viejo', 'status' => Task::STATUS_PENDING]);

    $this->putJson("/api/tasks/{$task->id}", [
        'title' => 'Título nuevo',
        'status' => Task::STATUS_COMPLETED,
    ])
        ->assertOk()
        ->assertJson(['title' => 'Título nuevo', 'status' => Task::STATUS_COMPLETED]);

    Http::assertNothingSent();
});

it('revalidates the user over http when the user_id changes', function () {
    $task = Task::factory()->create(['user_id' => 1]);

    Http::fake([
        '*' => Http::response(['id' => 7, 'name' => 'Luis Ramirez', 'email' => 'luis@example.com'], 200),
    ]);

    $this->putJson("/api/tasks/{$task->id}", ['user_id' => 7])
        ->assertOk()
        ->assertJson(['user_id' => 7]);

    Http::assertSent(
        fn ($request) => $request->url() === config('services.user_service.url').'/api/users/7'
    );
});

it('rejects the update when the new user_id does not exist in the user-service', function () {
    $task = Task::factory()->create(['user_id' => 1]);

    Http::fake([
        '*' => Http::response(['error' => 'Usuario no encontrado'], 404),
    ]);

    $this->putJson("/api/tasks/{$task->id}", ['user_id' => 99])->assertStatus(422);

    expect($task->fresh()->user_id)->toBe(1);
});

it('deletes a task', function () {
    $task = Task::factory()->create();

    $this->deleteJson("/api/tasks/{$task->id}")->assertNoContent();

    expect(Task::query()->count())->toBe(0);
});
