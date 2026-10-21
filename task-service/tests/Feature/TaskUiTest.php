<?php

use App\Models\Task;
use Demo\Microservices\UserServiceClient\TokenSession;
use Illuminate\Support\Facades\Http;

/*
| La UI usa exactamente el mismo UserServiceClient que la API: lo que se
| prueba aquí es el renderizado y el flujo de sesión, no la lógica microservicios.
*/

it('redirects anonymous visitors to the login page', function (string $uri) {
    Http::fake();

    $this->get($uri)->assertRedirect(route('login'));

    Http::assertNothingSent();
})->with([
    'index' => '/tasks',
    'create' => '/tasks/create',
    'edit' => '/tasks/1/edit',
]);

it('shows the login form', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Iniciar sesión', escape: false);
});

it('logs in through the user-service and stores the returned token', function () {
    Http::fake([
        '*/api/tokens' => Http::response(['token' => 'token-emitido'], 201),
    ]);

    $this->post(route('login'), [
        'email' => 'ana.torres@example.com',
        'password' => 'password',
    ])
        ->assertRedirect(route('tasks.index'))
        ->assertSessionHas(TokenSession::SESSION_KEY, 'token-emitido');

    Http::assertSent(
        fn ($request) => $request->url() === config('user-service-client.url').'/api/tokens'
            && $request['email'] === 'ana.torres@example.com'
    );
});

it('rejects invalid credentials without creating a session', function () {
    Http::fake([
        '*/api/tokens' => Http::response(['error' => 'Credenciales inválidas'], 401),
    ]);

    $this->from(route('login'))
        ->post(route('login'), ['email' => 'ana@example.com', 'password' => 'mala'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email')
        ->assertSessionMissing(TokenSession::SESSION_KEY);
});

it('returns 503 on the login page when the user-service is down', function () {
    fakeUserServiceDown();

    $this->post(route('login'), ['email' => 'ana@example.com', 'password' => 'password'])
        ->assertStatus(503);
});

it('logs out and revokes the token on the user-service', function () {
    loginAs();

    $this->post(route('logout'))
        ->assertRedirect(route('login'))
        ->assertSessionMissing(TokenSession::SESSION_KEY);

    Http::assertSent(
        fn ($request) => $request->method() === 'DELETE'
            && $request->url() === config('user-service-client.url').'/api/tokens'
            && $request->hasHeader('Authorization', 'Bearer test-token')
    );
});

it('lists only the tasks of the signed-in user', function () {
    $me = loginAs(['id' => 1, 'name' => 'Ana', 'email' => 'ana@example.com']);

    Task::factory()->create(['title' => 'Mi tarea', 'user_id' => $me['id']]);
    Task::factory()->create(['title' => 'Tarea de Luis', 'user_id' => 2]);

    $this->get(route('tasks.index'))
        ->assertOk()
        ->assertSee('Mi tarea')
        ->assertDontSee('Tarea de Luis')
        ->assertSee('Ana');
});

it('creates a task owned by the signed-in user', function () {
    loginAs(['id' => 9, 'name' => 'Luis', 'email' => 'luis@example.com']);

    $this->post(route('tasks.store'), [
        'title' => 'Desde el formulario',
        'status' => Task::STATUS_PENDING,
    ])->assertRedirect(route('tasks.index'));

    expect(Task::query()->first()->user_id)->toBe(9);
});

it('cannot be tricked through the form into assigning a task to someone else', function () {
    loginAs(['id' => 9, 'name' => 'Luis', 'email' => 'luis@example.com']);

    $this->post(route('tasks.store'), ['title' => 'Intrusa', 'user_id' => 1])
        ->assertRedirect(route('tasks.index'));

    expect(Task::query()->first()->user_id)->toBe(9);
});

it('updates and deletes its own task', function () {
    loginAs(['id' => 3, 'name' => 'Ana', 'email' => 'ana@example.com']);

    $task = Task::factory()->create(['title' => 'Vieja', 'user_id' => 3]);

    $this->put(route('tasks.update', $task), ['title' => 'Nueva'])
        ->assertRedirect(route('tasks.index'));

    expect($task->fresh()->title)->toBe('Nueva');

    $this->delete(route('tasks.destroy', $task))->assertRedirect(route('tasks.index'));

    expect(Task::query()->count())->toBe(0);
});

it('shows a 403 when opening a task that belongs to someone else', function () {
    loginAs(['id' => 1, 'name' => 'Ana', 'email' => 'ana@example.com']);

    $task = Task::factory()->create(['user_id' => 2]);

    $this->get(route('tasks.show', $task))->assertForbidden();
});

it('drops the web session when the token is no longer valid upstream', function () {
    $this->withSession([TokenSession::SESSION_KEY => 'token-caducado']);

    Http::fake(['*/api/user' => Http::response(['error' => 'Unauthenticated.'], 401)]);

    $this->get(route('tasks.index'))
        ->assertRedirect(route('login'))
        ->assertSessionMissing(TokenSession::SESSION_KEY);
});

it('returns 503 when the user-service is unreachable', function () {
    fakeUserServiceDown();

    $this->withSession([TokenSession::SESSION_KEY => 'test-token'])
        ->get(route('tasks.index'))
        ->assertStatus(503);
});
