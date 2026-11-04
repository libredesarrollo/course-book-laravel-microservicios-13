<?php

namespace App\Services\Jwt;

use App\Models\User;
use Carbon\CarbonInterface;
use Firebase\JWT\JWT;

/**
 * Emisor de access tokens JWT (RS256) del IdP.
 *
 * A diferencia del token opaco (ApiToken), aquí no se guarda nada: lo que se
 * entrega es una afirmación firmada — "quién es el usuario, hasta cuándo va
 * siendo válido" — que el RP puede verificar por sí solo contra la pública
 * publicada en /api/jwks.json. Ese es el stateless: la identidad viaja en el
 * token, no en una tabla que alguien tenga que consultar.
 *
 * Los claims son una foto del usuario al momento de emitir: si cambia el
 * email en el IdP, el JWT ya emitido sigue diciendo lo anterior — hasta que
 * expire. Es el precio del stateless, y el TTL corto es la mitigación.
 */
final readonly class JwtTokenIssuer
{
    public function __construct(private readonly JwtKeys $keys) {}

    /**
     * @return array{token: string, expires_at: CarbonInterface}
     */
    public function issue(User $user, string $deviceName): array
    {
        $issuedAt = now();
        $expiresAt = $issuedAt->copy()->addMinutes((int) config('jwt.ttl_minutes'));

        $payload = [
            // Creador del token. Los RP lo comparan contra su config: un JWT
            // firmado correctamente pero de otro emisor no vale.
            'iss' => (string) config('jwt.issuer'),

            // La foto de identidad. `sub` es el id del usuario; el resto son
            // claims de conveniencia para que el RP no pregunte de nuevo.
            'sub' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,

            // Momentos.
            'iat' => (int) $issuedAt->getTimestamp(),
            'exp' => (int) $expiresAt->getTimestamp(),

            // jti: id único del token. No se persiste (el stateless no
            // mantiene tablas), pero le sirve a los logs para seguir un
            // token concreto sin exponer su contenido.
            'jti' => bin2hex(random_bytes(16)),

            // Dónde se usó por primera vez. Es metadata, no seguridad.
            'device_name' => $deviceName,
        ];

        $token = JWT::encode($payload, $this->keys->privateKey(), 'RS256', $this->keys->kid());

        return ['token' => $token, 'expires_at' => $expiresAt];
    }
}
