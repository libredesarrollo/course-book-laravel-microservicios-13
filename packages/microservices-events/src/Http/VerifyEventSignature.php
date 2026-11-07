<?php

namespace Demo\Microservices\Events\Http;

use Closure;
use Demo\Microservices\Events\Outbox\Signature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware del lado RECEPTOR: antes de que el controller vea el JSON,
 * verifica firma del cuerpo, frescura del timestamp y anti-replay.
 *
 * Guardas, en orden:
 *
 * 1. Firma HMAC del cuerpo crudo con el secreto compartido: convierte
 *    "alguien me envió un JSON" en "alguien con la llave envió ESTE JSON".
 * 2. Frescura: X-Event-Timestamp dentro de una ventana (default 120 s);
 *    un cuerpo viejo firmado con la misma llave no vale si salió de ventana.
 * 3. Anti-replay con el event_id en caché: el emisor es at-least-once; la
 *    re-entrega idéntica se responde 200 SIN re-procesar.
 *
 * Si pasan las tres, deja los atributos listos en el request:
 *
 * - event_id y event_type (desde los headers firmables).
 * - event_envelope: el envelope completo, tal como lo firmó el emisor.
 * - event_payload: el payload del negocio, sin envoltura, para consumo directo.
 */
class VerifyEventSignature
{
    public const CACHE_PREFIX = 'microservices-events.seen.';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('events.secret');

        if ($secret === '') {
            logger()->warning('VerifyEventSignature: el receptor no configuró events.secret');

            return response()->json(['error' => 'Receptor no configurado'], 500);
        }

        $signature = (string) $request->header(Signature::HEADER);
        $timestamp = (int) $request->header(Signature::HEADER_TIMESTAMP, '0');
        $eventId = (string) $request->header(Signature::HEADER_EVENT_ID);
        $type = (string) $request->header(Signature::HEADER_TYPE);

        if ($this->tooOld($timestamp)) {
            logger()->warning('Webhook de eventos rechazado por frescura', ['event_id' => $eventId]);

            return response()->json(['error' => 'Evento fuera de la ventana de frescura'], 401);
        }

        if (! Signature::verify($request->getContent(), $secret, $signature)) {
            logger()->warning('Webhook de eventos con firma inválida', [
                'event_id' => $eventId,
                'type' => $type,
            ]);

            return response()->json(['error' => 'Firma de evento inválida'], 401);
        }

        if ($this->alreadySeen($eventId)) {
            // El emisor es at-least-once; esta re-entrega ya la procesamos.
            // Responder 200 aquí es lo correcto para que no siga reintentando.
            logger()->info('Webhook de eventos re-enviado: procesado antes, sin efecto', ['event_id' => $eventId]);

            return response()->json(['received' => true, 'event_id' => $eventId, 'status' => 'duplicate'], 200);
        }

        $envelope = $this->envelopeOf($request);

        $request->attributes->set('event_id', $eventId);
        $request->attributes->set('event_type', $type);
        $request->attributes->set('event_envelope', $envelope);
        $request->attributes->set('event_payload', (array) ($envelope['payload'] ?? []));

        return $next($request);
    }

    private function tooOld(int $timestamp): bool
    {
        if ($timestamp <= 0) {
            return true;
        }

        $maxAge = max(60, (int) config('events.max_age', 120));

        return abs(now()->getTimestamp() - $timestamp) > $maxAge;
    }

    /**
     * La marca del anti-replay. `Cache::add` es atómico: devuelve true solo
     * la PRIMERA vez (cuando la marca se acaba de crear); las re-entregas
     * obtienen false.
     */
    private function alreadySeen(string $eventId): bool
    {
        return ! Cache::add(
            self::CACHE_PREFIX.$eventId,
            now()->toIso8601String(),
            now()->addSeconds(max(60, (int) config('events.max_age', 120)) * 2),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function envelopeOf(Request $request): array
    {
        $envelope = $request->json()->all();

        return is_array($envelope) ? $envelope : [];
    }
}
