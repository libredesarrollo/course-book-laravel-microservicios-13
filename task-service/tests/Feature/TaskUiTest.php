<?php

use App\Models\Task;
use Illuminate\Support\Facades\Http;

/**
 * La UI web usa el mismo UserServiceClient que la API: lo que se prueba aquí
 * es el renderizado, no la lógica de microservicios (esa vive en TaskApiTest).
 */
function fakeUserService(int $userId = 1, string $name = 'Ana Torres', string $email = 'ana.torres@example.com'): void
{
    Http::fake([
        '*' => Http::response(['id' => $userId, 'name' => $name, 'email' => $email], 200),
    ]);
}

function fakeUserServiceDown(): void
{
    Http::fake(['*' => Http::failedConnection()]);
}

it('lists the stored tasks', function () {
    Task::factory()->create(['title' => 'Hacer la compra']);

    $this->get(route('tasks.index'))
        ->assertOk()
        ->assertSee('Hacer la compra');
});

it('populates the user select from the user-service', function () {
    Http::fake([
        config('services.user_service.url').'/api/users' => Http::response([
            'data' => [
                ['id' => 1, 'name' => 'Ana Torres', 'email' => 'ana.torres@example.com'],
                ['id' => 2, 'name' => 'Luis Ramirez', 'email' => 'luis.ramirez@example.com'],
            ],
        ], 200),
    ]);

    $this->get(route('tasks.create'))
        ->assertOk()
        ->assertSee('Ana Torres')
        ->assertSee('Luis Ramirez')
        ->assertSee(route('tasks.store'));

    Http::assertSent(
        fn ($request) => $request->url() === config('services.user_service.url').'/api/users'
    );
});

it('warns on the form when the user-service is down', function () {
    fakeUserServiceDown();

    $this->get(route('tasks.create'))
        ->assertOk()
        ->assertSee('user-service no disponible', escape: false);
});

it('creates a task and redirects to the list', function () {
    fakeUserService();

    $this->post(route('tasks.store'), ['title' => 'Nueva tarea', 'user_id' => 1, 'status' => Task::STATUS_PENDING])
        ->assertRedirect(route('tasks.index'))
        ->assertSessionHas('success');

    expect(Task::query()->where('title', 'Nueva tarea')->exists())->toBeTrue();
});

it('rejects the task and shows the error under the field when the user does not exist', function () {
    Http::fake([
        '*' => Http::response(['error' => 'Usuario no encontrado'], 404),
    ]);

    $this->from(route('tasks.create'))
        ->post(route('tasks.store'), ['title' => 'Tarea huerfana', 'user_id' => 42])
        ->assertRedirect(route('tasks.create'))
        ->assertSessionHasErrors('user_id');

    expect(Task::query()->count())->toBe(0);
});

it('returns a 503 page when the user-service is down on submit', function () {
    fakeUserServiceDown();

    $this->post(route('tasks.store'), ['title' => 'Tarea imposible', 'user_id' => 1])
        ->assertStatus(503)
        ->assertSee('user-service no disponible', escape: false);

    expect(Task::query()->count())->toBe(0);
});

it('updates a task', function () {
    $task = Task::factory()->create(['title' => 'Título viejo']);

    $this->put(route('tasks.update', $task), ['title' => 'Título nuevo', 'status' => Task::STATUS_COMPLETED])
        ->assertRedirect(route('tasks.index'));

    expect($task->fresh())
        ->title->toBe('Título nuevo')
        ->status->toBe(Task::STATUS_COMPLETED);
});

it('deletes a task', function () {
    $task = Task::factory()->create();

    $this->delete(route('tasks.destroy', $task))
        ->assertRedirect(route('tasks.index'));

    expect(Task::query()->count())->toBe(0);
});

it('resolves the owner name on the detail page via the user-service', function () {
    $task = Task::factory()->create(['user_id' => 5]);

    fakeUserService(5, 'Luis Ramirez', 'luis.ramirez@example.com');

    $this->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSee('Luis Ramirez')
        ->assertSee('luis.ramirez@example.com');
});

it('still shows the task when the user-service is down', function () {
    $task = Task::factory()->create(['title' => 'Tarea visible', 'user_id' => 5]);

    fakeUserServiceDown();

    $this->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSee('Tarea visible')
        ->assertSee('No se pudo resolver el nombre del propietario');
});
