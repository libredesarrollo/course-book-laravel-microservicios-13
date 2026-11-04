<?php

namespace Demo\Microservices\UserServiceClient\Jwt;

use Firebase\JWT\JWT;

/**
 * Verificación LOCAL de JWT del IdP: firma RS256 con la clave pública de la
 * JWKS cacheada, exp/iat con leeway y coherencia del issuer.
 *
 * Es exactamente lo que hasta b0.6 hacía la introspección por HTTP, pero sin red: el
 * salto de red que definía al sistema distribuido hasta b0.6 — un
 * GET /api/user por cada request — desaparece del camino regular. El IdP
 * sigue siendo la autoridad de EMISIÓN (login, SSO); deja de ser la
 * pregunta de cada página.
 *
 *  */
final class JwtVerifier
{
    public function __construct(private readonly JwkKeyStore $keys) {}

    /**
     * ¿Quién es el dueño de este JWT?
     *
     * @return array{id: int, name: string, email: string}|null null si la firma, la expiración, el issuer o los claims no cuadran
     */
    public function verify(string $token): ?array
    {
        if (substr_count($token, '.') !== 2) {
            return null;
        }

        try {
            JWT::$leeway = max(0, (int) config('user-service-client.jwt.leeway', 10));

            $claims = JWT::decode($token, $this->keys->keySet($this->kidOf($token)));
        } catch (\Throwable) {
            // Expirado, firma corrupta, kid desconocido a pesar del refetch,
            // claims incompletos: para el RP todo es "token inválido".
            return null;
        }

        if ((string) ($claims->iss ?? '') !== (string) config('user-service-client.jwt.issuer', 'user-service')) {
            return null;
        }

        if (! isset($claims->sub, $claims->name, $claims->email)) {
            return null;
        }

        return [
            'id' => (int) $claims->sub,
            'name' => (string) $claims->name,
            'email' => (string) $claims->email,
        ];
    }

    private function kidOf(string $token): ?string
    {
        $pieces = explode('.', $token);

        $header = json_decode(base64_decode($pieces[0], true) ?? '', true);

        return is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : null;
    }
}
