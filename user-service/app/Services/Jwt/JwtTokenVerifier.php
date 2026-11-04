<?php

namespace App\Services\Jwt;

use Firebase\JWT\JWT;

/**
 * Verificación de un JWT del IdP contra su propia clave pública.
 *
 * El IdP también recibe JWT — el middleware del propio /api/user corre por
 * aquí cuando mode=jwt. Uso exactamente la misma verificación que un RP:
 * firma RS256, exp/iat con leeway y coincidencia del issuer. La diferencia
 * es solo logística: no hay HTTP de por medio, la clave pública es local.
 */
final readonly class JwtTokenVerifier
{
    public function __construct(private readonly JwtKeys $keys) {}

    /**
     * @return array<string, mixed>|null los claims si el token es válido
     */
    public function verify(string $token): ?array
    {
        try {
            JWT::$leeway = max(0, (int) config('jwt.leeway', 10));

            $claims = JWT::decode($token, $this->keys->keySet());
        } catch (\Throwable) {
            // Expirado, firma corrupta, kid desconocido, formato roto: para
            // el cliente todo es "token inválido". La razón del rechazo va
            // al log, no a la respuesta.
            return null;
        }

        if ((string) ($claims->iss ?? '') !== (string) config('jwt.issuer')) {
            return null;
        }

        return (array) $claims;
    }
}
