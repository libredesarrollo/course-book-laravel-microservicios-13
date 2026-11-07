<?php

use App\Jobs\CreateInvoiceFromEvent;
use App\Models\Invoice;
use Demo\Microservices\Events\Outbox\Signature;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
| El receptor de billing es la cara CONSUMIDORA del patrón outbox: firma
| HMAC (middleware del paquete), "recibo y ya" (jobs) e idempotencia por
| event_id. Estos tests recorren las caras que importan del webhook.
*/

function eventEnvelope(string $eventId = '018f2e42-7b1c-7310-a52b-9ae4bdff22aa', string $type = 'task.completed'): array
{
    return [
        'event_id' => $eventId,
        'type' => $type,
        'occurred_at' => now()->toIso8601String(),
        'payload' => [
            'task_id' => 9,
            'task_title' => 'Comprar mármol para el piso',
            'user_id' => 7,
            'actor' => ['id' => 7, 'name' => 'Luis Ramirez', 'email' => 'luis@example.com'],
        ],
    ];
}

function signedEventRequest(?array $envelope = null, array $overrides = []): TestResponse
{
    $envelope ??= eventEnvelope();
    $body = (string) json_encode($envelope);
    $secret = config('events.secret');

    $headers = [
        'CONTENT_TYPE' => 'application/json',
        Signature::HEADER => $overrides[Signature::HEADER] ?? Signature::sign($body, (string) $secret),
        Signature::HEADER_EVENT_ID => $envelope['event_id'],
        Signature::HEADER_TYPE => $envelope['type'],
        Signature::HEADER_TIMESTAMP => $overrides[Signature::HEADER_TIMESTAMP] ?? (string) now()->getTimestamp(),
    ];

    return test()->call(
        'POST',
        '/api/events',
        [],
        [],
        [],
        test()->transformHeadersToServerVars($headers),
        $body,
    );
}

it('rejects events with a broken signature', function () {
    signedEventRequest(eventEnvelope(), [
        Signature::HEADER => 'no-es-firma',
    ])->assertStatus(401);

    expect(Invoice::query()->count())->toBe(0);
});

it('rejects events older than the freshness window', function () {
    signedEventRequest(eventEnvelope(), [
        Signature::HEADER_TIMESTAMP => (string) (now()->getTimestamp() - 3600),
    ])->assertStatus(401);
});

it('accepts a signed event and enqueues the invoice job', function () {
    Queue::fake();

    signedEventRequest()->assertStatus(202)->assertJson([
        'received' => true,
        'event_id' => '018f2e42-7b1c-7310-a52b-9ae4bdff22aa',
    ]);

    Queue::assertPushedOn('invoices', CreateInvoiceFromEvent::class);
});

it('acks unknown event types without jobs', function () {
    Queue::fake();

    signedEventRequest(eventEnvelope(type: 'task.imported'))->assertStatus(204);

    Queue::assertNothingPushed();
});

it('answers duplicates with the anti-replay cache and no second job', function () {
    Queue::fake();

    signedEventRequest()->assertStatus(202);
    signedEventRequest()->assertStatus(200)->assertJson(['status' => 'duplicate']);

    Queue::assertPushed(CreateInvoiceFromEvent::class, 1);
});

it('creates exactly one invoice even if the event is re-delivered', function () {
    // Sin Queue::fake: con QUEUE_CONNECTION=sync, el dispatch del receptor
    // corre el job al vuelo. Eso es exactamente lo que hará el worker.
    $envelope = eventEnvelope();

    signedEventRequest($envelope)->assertStatus(202);

    expect(Invoice::query()->count())->toBe(1);

    // La re-entrega del MISMO evento (at-least-once del outbox) vuelve a
    // correr el trabajo: la idempotencia está en event_id, no en la cola.
    // Un log info, no una segunda factura.
    (new CreateInvoiceFromEvent($envelope['event_id'], $envelope['payload']))->handle();

    expect(Invoice::query()->count())->toBe(1)
        ->and(Invoice::query()->first())->user_id->toBe(7)
        ->and(Invoice::query()->first())->task_title->toBe('Comprar mármol para el piso')
        ->and(Invoice::query()->first())->event_id->toBe('018f2e42-7b1c-7310-a52b-9ae4bdff22aa')
        ->and(Invoice::query()->first())->amount->toBe(10.0);
});

it('does not invoice events that arrive without a user_id', function () {
    Queue::fake();

    $envelope = eventEnvelope();
    data_set($envelope, 'payload.user_id', 0);

    signedEventRequest($envelope)->assertStatus(202);

    Queue::assertPushed(CreateInvoiceFromEvent::class, function (CreateInvoiceFromEvent $job) {
        $job->handle();

        return true;
    });

    expect(Invoice::query()->count())->toBe(0);
});
