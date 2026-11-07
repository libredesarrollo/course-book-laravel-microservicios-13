<?php

use App\Jobs\SendTaskCompletedEmail;
use App\Models\Task;
use Demo\Microservices\Events\Outbox\OutboxDelivery;
use Demo\Microservices\Events\Outbox\OutboxEvent;
use Demo\Microservices\Events\Outbox\Signature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
| El productor del capítulo: el evento vive en el OUTBOX (misma base, misma
| transacción que la tarea), y el dispatcher lo entrega a billing con firma
| HMAC y reintentos. Aquí probamos las dos caras del lado PRODUCTOR.
*/

function configuredSubscriber(): void
{
    config([
        'events.subscribers' => [
            'billing' => [
                'url' => 'https://billing.test/api/events',
                'secret' => 'secreto-de-prueba-task-billing',
                'events' => ['task.completed'],
            ],
        ],
    ]);
}

it('an unknown event has no subscribers and nothing leaves the outbox', function () {
    configuredSubscriber();

    Http::fake();

    OutboxEvent::record('task.imported', ['task_id' => 1], null);

    Artisan::call('outbox:dispatch');

    Http::assertNothingSent();
});

it('writes the task.completed outbox event in the same save', function () {
    Queue::fake();
    fakeUserService();

    $task = Task::factory()->create(['title' => 'Fregar los platos', 'user_id' => 1]);

    $this->withToken('test-token')
        ->withHeader('X-Request-Id', 'req-123')
        ->putJson("/api/tasks/{$task->id}", ['status' => Task::STATUS_COMPLETED])
        ->assertOk()
        ->assertJson(['status' => Task::STATUS_COMPLETED]);

    $event = OutboxEvent::query()->sole();

    expect($event->type)->toBe('task.completed')
        ->and($event->trace_id)->toBe('req-123')
        ->and($event->payload)->toMatchArray([
            'task_id' => $task->id,
            'task_title' => 'Fregar los platos',
            'user_id' => 1,
            'actor' => ['id' => 1, 'name' => 'Ana Torres', 'email' => 'ana.torres@example.com'],
        ]);

    // Con QUEUE_CONNECTION=sync los jobs corren en línea; para este test
    // faltó el mail en cola: aquí confirmamos la cola falsa lo capturó.
    Queue::assertPushed(SendTaskCompletedEmail::class);
});

it('does not emit the event twice for an already completed task', function () {
    Queue::fake();
    fakeUserService();

    $task = Task::factory()->create(['title' => 'Fregar los platos', 'user_id' => 1]);

    $this->withToken('test-token')
        ->putJson("/api/tasks/{$task->id}", ['status' => Task::STATUS_COMPLETED])
        ->assertOk();

    $this->withToken('test-token')
        ->putJson("/api/tasks/{$task->id}", ['status' => Task::STATUS_COMPLETED])
        ->assertOk();

    expect(OutboxEvent::query()->count())->toBe(1);
});

it('delivers the event to billing, signed', function () {
    configuredSubscriber();

    Http::fake(['billing.test/*' => Http::response([], 202)]);

    $task = Task::factory()->create(['title' => 'Fregar los platos', 'user_id' => 1]);
    OutboxEvent::record('task.completed', [
        'task_id' => $task->id,
        'task_title' => $task->title,
        'user_id' => 1,
        'actor' => ['id' => 1, 'name' => 'Ana Torres', 'email' => 'ana@example.com'],
    ], 'req-outbox-1');

    Artisan::call('outbox:dispatch');

    $event = OutboxEvent::query()->sole();
    $delivery = OutboxDelivery::query()->sole();

    expect($delivery->status)->toBe('delivered')
        ->and($delivery->attempts)->toBe(1)
        ->and($event->event_id)->not->toBeEmpty();

    Http::assertSent(function ($request) use ($event) {
        $body = (string) $request->body();
        $expectedSign = Signature::sign($body, 'secreto-de-prueba-task-billing');

        return $request->url() === 'https://billing.test/api/events'
            && $request->hasHeader(Signature::HEADER, $expectedSign)
            && $request->hasHeader('X-Event-Id', $event->event_id)
            && $request->hasHeader('X-Event-Type', 'task.completed')
            && $request->hasHeader('X-Request-Id', 'req-outbox-1')
            && str_contains($body, 'Fregar los platos');
    });
});

it('does not re-deliver the event on the second dispatch run', function () {
    configuredSubscriber();

    Http::fake(['billing.test/*' => Http::response([], 202)]);

    OutboxEvent::record('task.completed', ['task_id' => 1], null);

    Artisan::call('outbox:dispatch');
    Artisan::call('outbox:dispatch');

    Http::assertSentCount(1); // la segunda corrida no repite la entrega
});

it('retries the delivery and exhausts the attempts when billing is down', function () {
    configuredSubscriber();

    config([
        'events.max_attempts' => 2,
        'events.backoff' => [0, 0],
    ]);

    Http::fake(['billing.test/*' => Http::response(['error' => 'no pude'], 500)]);

    OutboxEvent::record('task.completed', ['task_id' => 1], null);

    Artisan::call('outbox:dispatch');

    $delivery = OutboxDelivery::query()->sole();
    expect($delivery->status)->toBe('pending')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_error)->toContain('no pude');

    // Segundo movimiento: agotados los intentos, marcada como failed.
    Log::shouldReceive('error')->once();

    Artisan::call('outbox:dispatch');

    $delivery->refresh();
    expect($delivery->status)->toBe('failed')
        ->and($delivery->attempts)->toBe(2);
});

it('keeps the delivery pending when billing refuses the connection', function () {
    configuredSubscriber();

    config(['events.backoff' => [10, 30]]);

    Http::fake(['billing.test/*' => function () {
        throw new ConnectionException(
            'Failed to connect to billing.test port 443: connection refused'
        );
    }]);

    OutboxEvent::record('task.completed', ['task_id' => 1], null);

    // La caída de red no rompe el dispatcher: la entrega queda pendiente
    // de nuevo, con su intento anotado y available_at conforme al backoff.
    Artisan::call('outbox:dispatch');

    $delivery = OutboxDelivery::query()->sole();

    expect($delivery->status)->toBe('pending')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_error)->toContain('Conexión fallida');
});

it('emits the event from the web UI too', function () {
    $user = loginAs();

    $task = Task::factory()->create(['title' => 'Fregar los platos', 'user_id' => $user['id']]);

    $this->put("/tasks/{$task->id}", ['title' => 'Fregar los platos', 'status' => Task::STATUS_COMPLETED])
        ->assertRedirect(route('tasks.index'));

    // El mail de una actualización web también corre; en tests usamos la
    // cola falsa de verdad del Job del framework.
    expect(OutboxEvent::query()->where('type', 'task.completed')->count())->toBe(1);
});
