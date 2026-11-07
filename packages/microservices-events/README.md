# demo/microservices-events

Colas y eventos entre microservicios en una red de servicios Laravel. El paquete
es **mecanismo sin política**: sabe subir y bajar mensajería de red, pero no sabe
qué significan los mensajes ni quién debe consumirlos — cada `config/events.php`
declara hacia dónde va cada cosa.

## Qué resuelve

Dos verdades incómodas de los microservicios y la respuesta de este paquete:

1. **No pongo el webhook en la misma transacción que la tarea.** Con este
   paquete el evento es una fila del outbox en **la misma base de datos** del
   productor, y nace (o no) en la misma transacción que la operación de
   negocio. Si la transacción se cae, el evento nunca existió. Si se confirma,
   el evento está ahí esperando; no queda "a ver qué pasó con la cola".
2. **¿Y si el receptor está caído?** El dispatcher entrega cada evento con
   **at-least-once**: reintentos con backoff hasta que el receptor confirme.
   Tarde, no nunca.

### Piezas

- `OutboxEvent` / `OutboxDelivery` — los modelos del outbox (`outbox_events`,
  `outbox_deliveries`).
- `OutboxDispatcher` — entrega firmada con historial de intentos, backoff y
  marcado `failed` agotados los intentos.
- `Signature` — HMAC SHA-256 del cuerpo; lo que se firma es exactamente la
  petición que viaja.
- `VerifyEventSignature` — middleware receptor: firma, frescura y anti-replay.
- `DispatchOutbox` — comando `outbox:dispatch {--limit=50}`.

## Instalación

```bash
composer require demo/microservices-events
php artisan vendor:publish --tag=events-migrations && php artisan migrate
```

El ServiceProvider se descubre solo y registra:

- `OutboxDispatcher` como singleton.
- El comando `outbox:dispatch`.
- El alias de middleware `events.verify`.

La config (`config/events.php`) sí se publica a mano si la quieres tocar: el
paquete la lee tal cual (`mergeConfigFrom`) y en las apps de esta serie vive
gestionada a mano en `config/events.php` de cada servicio.

## Configuración

```php
// El productor declara a quién le importa cada tipo de evento:
'subscribers' => [
    'billing' => [
        'url' => env('BILLING_EVENTS_URL', 'http://127.0.0.1:8002/api/events'),
        'secret' => env('BILLING_EVENTS_SECRET'),
        'events' => ['task.completed'],
    ],
],
```

| Clave | Qué hace | Default |
|---|---|---|
| `subscribers.<nombre>.url` | Endpoint receptor. | — |
| `subscribers.<nombre>.secret` | Clave HMAC compartida con ese receptor. | — |
| `subscribers.<nombre>.events` | Tipos que este subscriptor consume. | `'*'` |
| `timeout` | Timeout HTTP de cada entrega, en segundos. | `3` |
| `max_attempts` | Intentos totales antes de marcar `failed`. | `5` |
| `backoff` | Segundos de espera tras el intento 1, 2, 3... (el último se repite). | `[10, 30, 120]` |
| `max_age` | Anti-replay del receptor: headers con más de esa edad se rechazan. | `120` |

**Secrets**: cada par productor↔receptor tiene su clave, y cada una vive
independiente. Variables distintas por lado (`BILLING_EVENTS_SECRET` en task,
`TASK_EVENTS_SECRET` en billing) con el mismo valor.

## Emisión (productor)

El patrón outbox: el evento se escribe en la misma transacción que la operación
de negocio, nunca como efecto secundario después del commit:

```php
DB::transaction(function () use ($task, $actor) {
    $task->update(['status' => 'completed']);

    OutboxEvent::record('task.completed', [
        'task_id' => $task->id,
        'task_title' => $task->title,
        'user_id' => $task->user_id,
        'actor' => $actor,
    ], request()->attributes->get('request_id'));
});
```

`record()` genera el `event_id` (UUID), guarda `type`, `payload`, `trace_id` y
`occurred_at`. Los subscriptores se deciden al despachar, no en la escritura:
el outbox no sabe nada de billing.

El envío corre con el scheduler o a mano:

```php
Schedule::command(DispatchOutbox::class)->everyMinute()->withoutOverlapping();
```

```bash
php artisan outbox:dispatch
```

Si el receptor no responde, la entrega no se pierde: el dispatcher reintenta
con el backoff de `config('events.backoff')` y marca la entrega como `failed`
tras agotar `max_attempts`, con su `-error` en el log. Un fallo de red no rompe
el comando ni aborta las demás entregas: cada entrega lleva su propia cuenta,
pending hasta lograrlo.

## Recepción

El receptor declara el endpoint y el middleware hace la verificación:

```php
Route::post('/events', EventController::class)->middleware('events.verify');
```

El middleware rechaza barato y por orden:

1. Secret no configurado → `500` con un mensaje que dice cómo arreglarlo.
2. Header `X-Event-Timestamp` más viejo de `max_age` → `401` (replay lejano).
3. Firma HMAC inválida (con `hash_equals`, sin filtración de timing) → `401`.
4. Anti-replay: `event_id` ya visto → `200` con `{"status": "duplicate"}` —
   una re-entrega reconocida no es un error (el `200` ayuda a que el dispatcher
   no la cuente como fallo).
5. Todo bien → attributes en el request: `event_envelope` (el JSON completo),
   `event_id`, `event_type` y `event_payload` **(solo el interior)**.

El controlador decide qué hacer con cada tipo. Los reconocidos se despachan a
la cola del receptor y se responde `202` al instante; el trabajo pesado corre
asincrónico:

```php
if ($envelope['type'] === 'task.completed') {
    CreateInvoiceFromEvent::dispatch($envelope['event_id'], $envelope['payload'])
        ->onQueue('invoices');

    return response()->json(['received' => true, 'event_id' => $envelope['event_id']], 202);
}

return response()->noContent();
```

## Idempotencia en el receptor

**El dispatcher impone at-least-once**: un evento puede llegar más de una vez
(proceso matado tras aceptar el webhook pero antes de grabar la entrega; red
reenviando lo que ya mandó; despachos en racha). La garantía vive en el
receptor, en dos capas:

1. **Cache anti-replay por `event_id`** — primera línea: marca atómica con
   `Cache::add()`; una re-entrega dentro de la ventana sale por aquí.
2. **`event_id` UNIQUE en la fila destino** — última línea: aunque el cache se
   evapore (driver `array` y un deploy), la segunda factura no nace; el job lo
   trata como camino feliz:

```php
try {
    Invoice::create([...]);
} catch (UniqueConstraintViolationException) {
    // at-least-once: esto no es un error, ya la hicimos la primera vez.
    Log::info('Evento ya procesado por idempotencia', ['event_id' => $eventId]);

    return;
}
```

Las dos capas juntas: una y solo una factura por evento, aunque el emisor sea
repetidor empedernido.

## Trazabilidad

El `trace_id` con el que se grabó el evento viaja como header `X-Request-Id`
en cada entrega, para que la traza cruce servicios; funciona junto con
`demo/microservices-observability`.

## Verificado con los tres servicios reales

| Hecho | Valor medido |
|---|---|
| Recibo del usuario con billing muerto (`PUT /api/tasks/{id}`) | `200 OK` en ~8 ms |
| Webhook recibido en billing | `202` con job en la cola en ~60 ms |
| Factura nace cuando corre `queue:work --queue=invoices` | asincrónico, igual |
| Re-entrega del mismo evento firmado | `200` `{"status": "duplicate"}`, 1 factura |
| Billing muerto + `outbox:dispatch` | reintento con backoff; el comando no se cae |
| Billing de vuelta + `outbox:dispatch` | `delivered` con los intentos anotados |

## Tests

Tanto emisión como recepción se prueban sin red real:

```php
// Productor: intercepta las entregas.
Http::fake(['billing.test/*' => Http::response([], 202)]);
Artisan::call('outbox:dispatch');
Http::assertSent(function ($request) { /* headers + firma + cuerpo */ });
```

```php
// Receptor: firma el evento tal como lo haría el productor.
$signature = Signature::sign($body, 'secreto-de-prueba');
$this->postJson('/api/events', $body, ['X-Event-Signature' => $signature, /* ... */]);
```

## Formateo

El paquete se formatea con el preset de Laravel desde un servicio que lo consume:

```bash
cd task-service && vendor/bin/pint ../packages/microservices-events
```
