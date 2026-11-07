<?php

namespace Demo\Microservices\Events\Outbox;

/**
 * La firma HMAC del cuerpo JSON del evento: SHA-256 con un secreto
 * compartido entre productor y receptor.
 *
 * Es el "sobre cerrado" del webhook: cualquiera puede intentar POST al
 * receptor, pero solo quien posee el secreto genera una firma válida. En
 * la práctica tiene una consecuencia directa: cada par productor/receptor
 * lleva su propio secreto (no hay un único secreto para todo el sistema).
 */
class Signature
{
    /** Header donde viaja la firma. */
    public const HEADER = 'X-Event-Signature';

    /** Header del id del evento (para correlacionar sin leer el JSON). */
    public const HEADER_EVENT_ID = 'X-Event-Id';

    /** Header del tipo del evento. */
    public const HEADER_TYPE = 'X-Event-Type';

    /** Header del timestamp de emisión (frescura anti-replay). */
    public const HEADER_TIMESTAMP = 'X-Event-Timestamp';

    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /**
     * Verificación en tiempo constante: hash_equals, nunca `===`.
     */
    public static function verify(string $body, string $secret, string $signature): bool
    {
        $given = trim($signature);

        if ($given === '' || (bool) str_contains($given, ' ')) {
            return false;
        }

        return hash_equals(self::sign($body, $secret), strtolower($given));
    }
}
