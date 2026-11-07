<?php

namespace Demo\Microservices\Events\Outbox;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * El repartidor del outbox: entrega los eventos pendientes a los
 * subscriptores registrados en config('events.subscribers').
 *
 * Garantías, para tenerlas claras:
 *
 * - AT-LEAST-ONCE (no exactly-once): un intento que se corta a medio camino
 *   deja la entrega pendiente y vuelve a su hora; el receptor garantiza la
 *   idempotencia con el event_id.
 * - Los reintentos NO usan el circuit breaker del cliente HTTP: una entrega
 *   fallida vuelve por available_at, con backoff de config('events.backoff').
 *   Con config('events.max_attempts') agotados marca 'failed' para que un
 *   humano la vea en el log, sin bloquear el reparto del resto.
 *
 * @phpstan-type Subscriber array{url: string, secret: string|null, events: list<string>}
 */
class OutboxDispatcher
{
    /**
     * Entrega eventos pendientes. El valor de retorno son ENTREGAS hechas
     * (un evento entregado a dos subscriptores cuenta dos).
     */
    public function dispatchPending(?int $limit = 50): int
    {
        $delivered = 0;

        OutboxEvent::query()
            ->with('deliveries')
            ->where('available_at', '<=', now())
            ->orderBy('occurred_at')
            ->limit(max(1, (int) $limit))
            ->get()
            ->each(function (OutboxEvent $event) use (&$delivered): void {
                foreach ($this->subscribersFor($event->type) as $name => $subscriber) {
                    $delivery = $this->ensureDelivery($event, (string) $name);

                    // Ya entregada (o agotada): no se repite. Si un receptor
                    // necesita el evento de nuevo, la repetición corre de su
                    // cuenta, guiada por el event_id.
                    if ($delivery->status !== 'pending') {
                        continue;
                    }

                    if ($this->deliverTo($event, (string) $name, $subscriber, $delivery)) {
                        $delivered++;
                    }
                }
            });

        return $delivered;
    }

    /**
     * @return array<string, Subscriber>
     */
    private function subscribersFor(string $type): array
    {
        return collect((array) config('events.subscribers', []))
            ->filter(fn (array $subscriber): bool => in_array($type, (array) ($subscriber['events'] ?? []), true))
            ->filter(fn (array $subscriber): bool => filled($subscriber['url'] ?? null))
            ->all();
    }

    private function ensureDelivery(OutboxEvent $event, string $subscriber): OutboxDelivery
    {
        return OutboxDelivery::query()->firstOrCreate(
            ['outbox_event_id' => $event->id, 'subscriber' => $subscriber],
            ['status' => 'pending', 'attempts' => 0, 'available_at' => now()],
        );
    }

    /**
     * @param  Subscriber  $subscriber
     */
    private function deliverTo(OutboxEvent $event, string $name, array $subscriber, OutboxDelivery $delivery): bool
    {
        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        $body = (string) json_encode($this->manifest($event));
        $signature = Signature::sign($body, (string) $subscriber['secret']);

        // Sin circuit breaker de por medio: la entrega fallida no dispara
        // pánico, vuelve por available_at. Así el receptor caído pesa como
        // lo que es: un retraso, no una pérdida.
        try {
            $response = Http::withHeaders([
                // El HMAC cubre el cuerpo crudo. Id, tipo y timestamp viajan
                // en headers para que el receptor verifique frescura y firma
                // sin descomponer el JSON.
                'X-Event-Signature' => $signature,
                'X-Event-Id' => $event->event_id,
                'X-Event-Type' => $event->type,
                'X-Event-Timestamp' => (string) now()->getTimestamp(),
                'X-Request-Id' => $event->trace_id ?? '',
            ])
                ->acceptJson()
                ->timeout((int) config('events.timeout', 3))
                ->withBody($body, 'application/json')
                ->post((string) $subscriber['url']);
        } catch (ConnectionException $exception) {
            $failedDescription = 'Conexión fallida: '.substr($exception->getMessage(), 0, 200);
            $this->recordFailure($delivery, $name, $event, $failedDescription);

            return false;
        }

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => 'delivered',
                'delivered_at' => now(),
                'last_error' => null,
            ])->save();

            return true;
        }

        $this->recordFailure($delivery, $name, $event,
            'HTTP '.$response->status().': '.substr($response->body() ?? '', 0, 256));

        return false;
    }

    private function recordFailure(OutboxDelivery $delivery, string $name, OutboxEvent $event, string $reason): void
    {
        $max = (int) config('events.max_attempts', 5);
        $expired = $delivery->attempts >= $max;

        $delivery->forceFill([
            'status' => $expired ? 'failed' : 'pending',
            'available_at' => now()->addSeconds($this->backoffSeconds($delivery->attempts)),
            'last_error' => substr($reason, 0, 500),
        ])->save();

        if ($expired) {
            logger()->error('Outbox: agotados los reintentos de entrega', [
                'subscriber' => $name,
                'event_id' => $event->event_id,
                'type' => $event->type,
                'last_error' => $delivery->last_error,
            ]);
        }
    }

    /**
     * Backoff de la lista config('events.backoff'), con el último paso
     * repetido en los intentos que exceden la lista: [10, 30, 120] → los
     * intentos 4 y 5 esperan 120 s.
     */
    private function backoffSeconds(int $attempt): int
    {
        $steps = (array) config('events.backoff', [10, 30, 120]);
        $index = min(max(0, $attempt - 1), max(0, count($steps) - 1));

        return (int) ($steps[$index] ?? 10);
    }

    /**
     * El cuerpo que se firma y se publica: id, type, occurred_at y payload.
     *
     * @return array<string, mixed>
     */
    public static function manifest(OutboxEvent $event): array
    {
        return [
            'event_id' => $event->event_id,
            'type' => $event->type,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'payload' => $event->payload,
        ];
    }
}
