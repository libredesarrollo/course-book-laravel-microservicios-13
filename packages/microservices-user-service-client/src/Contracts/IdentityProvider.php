<?php

namespace Demo\Microservices\UserServiceClient\Contracts;

use Demo\Microservices\UserServiceClient\Exceptions\UserServiceErrorException;
use Demo\Microservices\UserServiceClient\Exceptions\UserServiceUnavailableException;

/**
 * Contrato de un proveedor de identidad remoto.
 *
 * El task-service no tiene una tabla de usuarios: para saber quién es el
 * cliente tiene que preguntárselo al user-service por HTTP. Esta interfaz
 * es el contrato de esa frontera de red, para que el resto de la aplicación
 * (middlewares, controllers) dependa de una abstracción y no de un cliente
 * HTTP concreto.
 */
interface IdentityProvider
{
    /**
     * Resuelve quién es el dueño de un token opaco.
     *
     * @return array{id: int, name: string, email: string}|null null si el token no es válido
     *
     * @throws UserServiceUnavailableException
     * @throws UserServiceErrorException
     */
    public function authenticate(string $token): ?array;

    /**
     * Pide un token a cambio de credenciales.
     *
     * @param  array{email: string, password: string, device_name?: string}  $credentials
     *
     * @throws UserServiceUnavailableException
     * @throws UserServiceErrorException
     */
    public function issueToken(array $credentials): string;

    /**
     * Revoca un token en el user-service.
     *
     * @throws UserServiceUnavailableException
     * @throws UserServiceErrorException
     */
    public function revokeToken(string $token): void;

    /**
     * Intercambia un código SSO de un solo uso por un token para el cliente.
     *
     * @return array{token: string, token_type: string, expires_at: string|null, user: array{id: int, name: string, email: string, created_at: string|null, updated_at: string|null}}|null
     *
     * @throws UserServiceUnavailableException
     * @throws UserServiceErrorException
     */
    public function exchangeCode(string $code, string $client): ?array;
}
