<?php

namespace Demo\Microservices\UserServiceClient;

use Illuminate\Http\Request;

/**
 * Identidad del cliente que hace el request.
 *
 * El consumidor no tiene usuarios: los conoce solo durante el request, y
 * solo porque el user-service se los acaba de confirmar por HTTP. Este
 * value object es la forma que toma esa respuesta dentro de la aplicación.
 *
 * Vive en el paquete porque es parte de su contrato: el middleware la deja
 * en los atributos del request y la aplicación la lee desde ahí. La clave
 * del atributo también es del paquete, para que no se disperse como string
 * suelto por el código de la aplicación.
 */
final readonly class AuthenticatedUser
{
    /**
     * Clave del atributo del request donde se guarda la identidad.
     */
    public const ATTRIBUTE = 'authenticated_user';

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

    /**
     * Recupera la identidad que dejó el middleware, si la hay.
     */
    public static function fromRequest(Request $request): ?self
    {
        $user = $request->attributes->get(self::ATTRIBUTE);

        return $user instanceof self ? $user : null;
    }
}
