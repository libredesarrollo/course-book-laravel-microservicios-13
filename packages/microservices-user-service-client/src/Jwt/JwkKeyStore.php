<?php

namespace Demo\Microservices\UserServiceClient\Jwt;

use Demo\Microservices\UserServiceClient\Exceptions\UserServiceUnavailableException;
use Firebase\JWT\JWK;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Catálogo de claves públicas del IdP, obtenido del endpoint JWKS.
 *
 * Es la pieza que convierte la verificación en local: la clave pública se
 * baja UNA vez, queda en la caché de la aplicación durante
 * user-service-client.jwt.jwks_cache_ttl, y de ahí en adelante el RP valida
 * firmas con cero llamadas de red. Cuando llegue un `kid` desconocido
 * (rotación de claves en el IdP), se fuerza un refetch — la rotación no
 * requiere despliegue en los RPs.
 *
 * El estado en caché también explica por qué este bloque "casi no usa" el
 * circuit breaker: la única llamada de red del flujo son los segundos
 * posteriores al arranque. Si el IdP no está en ese momento exacto, la
 * verificación falla como cualquier otra consulta al IdP; con la JWKS en
 * caché, la red desaparece del camino.
 */
final class JwkKeyStore
{
    private const CACHE_KEY = 'user-service-client.jwt.jwks';

    /**
     * @return array<string, Firebase\JWT\Key> keyset parseado por kid, listo para JWT::decode
     */
    public function keySet(?string $missingKid = null): array
    {
        $raw = $this->raw(force: false);

        if ($missingKid !== null && ! $this->hasKid($raw, $missingKid)) {
            $raw = $this->raw(force: true);
        }

        return JWK::parseKeySet($raw, 'RS256');
    }

    /**
     * La JWKS cruda, cacheada si el ttl es mayor que cero.
     *
     * Con ttl 0 la JWKS se pregunta por HTTP en cada verificación: cero
     * estado local, el precio es un salto de red por request — el mismo
     * trade-off de always del middleware, ahora para las claves.
     *
     * @return array<string, mixed>
     */
    private function raw(bool $force): array
    {
        $ttl = max(0, (int) config('user-service-client.jwt.jwks_cache_ttl', 3600));

        if (! $force && $ttl > 0) {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached) && $this->hasKid($cached, null)) {
                return $cached;
            }
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('user-service-client.timeout', 3))
                ->get((string) config('user-service-client.jwt.jwks_url'));
        } catch (ConnectionException $exception) {
            throw new UserServiceUnavailableException(
                'No se pudo obtener la JWKS del user-service.',
                previous: $exception,
            );
        }

        if ($response->failed() || ! is_array($response->json('keys'))) {
            throw new UserServiceUnavailableException(
                'El user-service no entregó una JWKS válida ('.$response->status().').'
            );
        }

        $keys = (array) $response->json();

        if ($ttl > 0) {
            Cache::put(self::CACHE_KEY, $keys, now()->addSeconds($ttl));
        }

        return $keys;
    }

    /**
     * ¿La JWKS contiene una clave (cualquiera, o ese kid exacto)?
     *
     * @param  array<string, mixed>  $raw
     */
    private function hasKid(array $raw, ?string $kid): bool
    {
        $keys = $raw['keys'] ?? null;

        if (! is_array($keys)) {
            return false;
        }

        if ($kid === null) {
            return $keys !== [];
        }

        foreach ($keys as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return true;
            }
        }

        return false;
    }
}
