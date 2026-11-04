<?php

namespace App\Services\Jwt;

use App\Models\ApiToken;
use App\Models\User;

/**
 * Fachada de emisión de access tokens, con el modo en config.
 *
 * Los dos modos responden EXACTAMENTE lo mismo al RP — token, token_type,
 * expires_at y user — para que task-service y billing-service ni conozcan
 * la diferencia. La filosofía de la serie: la frontera no cambia, cambia lo
 * que hay detrás.
 *
 * - jwt:    JwtTokenIssuer firma un JWT; no se guarda nada en la tabla
 *           api_tokens. Revocación = expiración corta.
 * - opaque: ApiToken::issueFor crea la fila con SHA-256 y devuelve el
 *           texto plano una sola vez. Revocación = DELETE /api/tokens.
 */
final readonly class AccessTokenIssuer
{
    public function __construct(private readonly JwtTokenIssuer $jwtIssuer) {}

    /**
     * @return array{token: string, token_type: string, expires_at: string|null, api_token: ApiToken|null}
     */
    public function issue(User $user, string $deviceName): array
    {
        if (config('tokens.mode') === 'jwt') {
            $issued = $this->jwtIssuer->issue($user, $deviceName);

            return [
                'token' => $issued['token'],
                'token_type' => 'Bearer',
                'expires_at' => $issued['expires_at']->toIso8601String(),
                'api_token' => null,
            ];
        }

        $opaque = ApiToken::issueFor(
            $user,
            $deviceName,
            now()->addMinutes((int) config('tokens.ttl_minutes')),
        );

        return [
            'token' => $opaque['token'],
            'token_type' => 'Bearer',
            'expires_at' => $opaque['model']->expires_at?->toIso8601String(),
            'api_token' => $opaque['model'],
        ];
    }
}
