<?php

namespace App\Support;

/**
 * Identidad del cliente que hace el request.
 *
 * El task-service no tiene usuarios: los conoce solo durante el request, y
 * solo porque el user-service se los acaba de confirmar por HTTP.
 *
 * @property-read int $id
 */
final readonly class AuthenticatedUser
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
    ) {}

    /**
     * @param  array{id: int, name: string, email: string}  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: (int) $payload['id'],
            name: (string) $payload['name'],
            email: (string) $payload['email'],
        );
    }
}
