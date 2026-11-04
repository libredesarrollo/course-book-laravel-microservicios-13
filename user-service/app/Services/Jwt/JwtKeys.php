<?php

namespace App\Services\Jwt;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * Manejo del par de claves de firma JWT (RS256).
 *
 * La clave privada vive solo aquí, en user-service. Los relying parties
 * nunca la ven: de eso va el stateless todo. La pública la publicamos
 * directamente vía /api/jwks.json — compacta con b64url y el kid derivado
 * de su hash, para que sea estable mientras no se regenere el par.
 *
 * OK para producción: la rotación de claves es un despliegue — los RP
 * refetch la JWKS cuando les llega un kid desconocido.
 */
final readonly class JwtKeys
{
    /**
     * Resuelve el PEM de la clave privada, o aborta si no existe.
     *
     * Sin par de claves generado, /api/tokens con mode=jwt no debe operar
     * "esperemos" y fallar distinto: es un error de despliegue.
     */
    public function privateKey(): string
    {
        $path = (string) config('jwt.private_key');

        if (! is_file($path)) {
            throw new \RuntimeException(
                "Falta la clave privada JWT ({$path}). Genera el par con: php artisan user-service:jwt-keys"
            );
        }

        return (string) file_get_contents($path);
    }

    public function publicKey(): string
    {
        $path = (string) config('jwt.public_key');

        if (! is_file($path)) {
            throw new \RuntimeException(
                "Falta la clave pública JWT ({$path}). Genera el par con: php artisan user-service:jwt-keys"
            );
        }

        return (string) file_get_contents($path);
    }

    /**
     * kid de la clave pública (deriva del hash de su número módulo).
     *
     * Stable mientras no se regenere el par, y a la vez distinto para un par
     * nuevo — exactamente lo que un JWKS necesita para rotar.
     */
    public function kid(): string
    {
        $details = openssl_pkey_get_details($this->publicKeyResource());

        return substr(bin2hex(hash('sha256', (string) $details['rsa']['n'], true)), 0, 16);
    }

    /**
     * La clave pública como JWK lista para el endpoint JWKS.
     *
     * @return array{keys: list<array<string, mixed>>}
     */
    public function jwks(): array
    {
        return [
            'keys' => [$this->jwk()],
        ];
    }

    /**
     * El mismo JWK, con kid, listo para el endpoint JWKS y para keysets.
     */
    public function jwk(): array
    {
        $details = openssl_pkey_get_details($this->publicKeyResource());

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $this->kid(),
            'n' => self::b64url((string) $details['rsa']['n']),
            'e' => self::b64url((string) $details['rsa']['e']),
        ];
    }

    /**
     * La clave pública armada como keyset para verificación local — es la
     * misma verificación que hará un relying party, solo que aquí no hay
     * red de por medio.
     *
     * @return array<string, Firebase\JWT\Key>
     */
    public function keySet(): array
    {
        return JWK::parseKeySet(['keys' => [$this->jwk()]], 'RS256');
    }

    private function publicKeyResource(): \OpenSSLAsymmetricKey|false
    {
        return openssl_pkey_get_public($this->publicKey());
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
