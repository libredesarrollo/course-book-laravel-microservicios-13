<?php

namespace Demo\Microservices\UserServiceClient\Resiliency;

use Illuminate\Support\Facades\Cache;

/**
 * Circuit breaker del cliente HTTP, respaldado por la caché de la aplicación.
 *
 * El estado vive en la caché (file en desarrollo local), no en memoria: así
 * el breaker lo comparten todos los requests de la aplicación sin depender
 * de nada externo. Es "barato" a propósito; el IdP ya impone el costo real.
 *
 * El protocolo es el clásico de tres estados:
 * - closed: pasa todo; se cuentan los fallos consecutivos (timeouts y 5xx).
 * - open: alcanzado el umbral, se rechaza al instante sin llamar al IdP. Es
 *   lo que convierte una caída de 3 s por request en una de milisegundos.
 * - half-open: vencido el cooldown, vuelve a pasar tráfico; si el IdP sigue
 *   caído, la siguiente racha de fallos reabre el circuito.
 *
 * Consecuencia honesta: con el circuito abierto y sin caché del usuario, un
 * RP no puede autenticar a nadie. Falla rápido (503) en vez de lento (3 s).
 */
class CircuitBreaker
{
    private const OPEN_UNTIL = 'user-service-client.circuit.open_until';

    private const FAILURES = 'user-service-client.circuit.failures';

    /**
     * El circuito está abierto y el cooldown no ha vencido.
     */
    public function isBlocked(): bool
    {
        return now()->getTimestamp() < (int) Cache::get(self::OPEN_UNTIL, 0);
    }

    /**
     * Una respuesta (incluso un 4xx) prueba que el servicio está vivo.
     */
    public function recordSuccess(): void
    {
        Cache::forget(self::OPEN_UNTIL);
        Cache::forget(self::FAILURES);
    }

    /**
     * Cuenta un fallo de infraestructura. Con el umbral alcanzado, abre
     * el circuito durante el cooldown configurado.
     */
    public function recordFailure(): void
    {
        $failures = (int) Cache::get(self::FAILURES, 0) + 1;

        Cache::put(self::FAILURES, $failures, now()->addHours(1));

        if ($failures >= $this->threshold()) {
            Cache::forget(self::FAILURES);

            $cooldown = max(1, (int) config('user-service-client.circuit_cooldown', 30));

            Cache::put(
                self::OPEN_UNTIL,
                now()->getTimestamp() + $cooldown,
                now()->addSeconds($cooldown + 5),
            );
        }
    }

    private function threshold(): int
    {
        return max(1, (int) config('user-service-client.circuit_threshold', 3));
    }
}
