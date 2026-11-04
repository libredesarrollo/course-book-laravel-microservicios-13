<?php

namespace Demo\Microservices\UserServiceClient\Jwt;

use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;
use Demo\Microservices\UserServiceClient\Exceptions\UserServiceErrorException;
use Demo\Microservices\UserServiceClient\Exceptions\UserServiceUnavailableException;
use Demo\Microservices\UserServiceClient\UserServiceClient;

/**
 * Implementación del contrato IdentityProvider que verifica LOCALMENTE.
 *
 * El contrato contiene cuatro verbos con destinos muy distintos:
 *
 * - authenticate(): LA verificación de identidad. Aquí es donde el JWT
 *   stateless vale: la firma se comprueba en el proceso del RP con la
 *   clave pública cacheada — cero salto de red, cero necesidad de que el
 *   IdP esté vivo en cada página. Un token opaco (sin forma de JWT) cae al
 *   cliente HTTP: modo dual sin conmutador, guiado por la forma del token.
 *
 * - issueToken() / revokeToken() / exchangeCode(): emisores y estado del
 *   IdP. Como no se puede delegar en matemática lo que es un acto de
 *   autoridad (emitir, revocar, canjear SSO), siguen hablando HTTP con el
 *   user-service — ahora entregando JWT en vez de opacos.
 *
 * Así el resto del paquete (middlewares, controllers) no cambia una línea:
 * dependen del contrato, y el contrato ahora responde local.
 */
final readonly class JwtIdentityProvider implements IdentityProvider
{
    public function __construct(
        private readonly UserServiceClient $clients,
        private readonly JwtVerifier $verifier,
    ) {}

    /**
     * @return array{id: int, name: string, email: string}|null null si el token no es válido
     *
     * @throws UserServiceUnavailableException
     * @throws UserServiceErrorException
     */
    public function authenticate(string $token): ?array
    {
        $user = $this->verifier->verify($token);

        if ($user !== null) {
            return $user;
        }

        if (substr_count($token, '.') === 2) {
            // Parecía JWT y no lo es: firmado por otro iss, expirado,
            // corrompido. No hay a quién preguntarle vía HTTP — un JWT no
            // vive en ninguna tabla del IdP.
            return null;
        }

        return $this->clients->authenticate($token);
    }

    /**
     * @param  array{email: string, password: string, device_name?: string}  $credentials
     *
     * @throws UserServiceUnavailableException
     * @throws UserServiceErrorException
     */
    public function issueToken(array $credentials): string
    {
        return $this->clients->issueToken($credentials);
    }

    /**
     * @throws UserServiceUnavailableException
     * @throws UserServiceErrorException
     */
    public function revokeToken(string $token): void
    {
        $this->clients->revokeToken($token);
    }

    /**
     * @throws UserServiceUnavailableException
     * @throws UserServiceErrorException
     */
    public function exchangeCode(string $code, string $client): ?array
    {
        return $this->clients->exchangeCode($code, $client);
    }
}
