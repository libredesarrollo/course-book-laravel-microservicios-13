<?php

namespace App\Services;

use App\Exceptions\UserServiceErrorException;
use App\Exceptions\UserServiceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP del user-service.
 *
 * Todo el conocimiento sobre "cómo se habla con el user-service" vive aquí:
 * la URL base (config/services.php), el timeout, el manejo de errores de red
 * y la forma del JSON. Los controllers no saben nada del tema.
 *
 * Es exactamente el patrón de un API Gateway / service client: encapsular la
 * frontera de red para que el resto de la aplicación trate al otro
 * microservicio como si fuera una función local.
 */
class UserServiceClient
{
    /**
     * Busca un usuario por su id.
     *
     * @return array{id: int, name: string, email: string}|null null si el usuario no existe
     *
     * @throws UserServiceUnavailableException si el user-service no está alcanzable
     * @throws UserServiceErrorException si el user-service responde con error
     */
    public function find(int $id): ?array
    {
        $response = $this->request("/api/users/{$id}");

        // 404 no es un error: es la respuesta esperada cuando el usuario no existe.
        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new UserServiceErrorException(
                "El user-service respondió {$response->status()} al buscar el usuario {$id}."
            );
        }

        return $response->json();
    }

    /**
     * Lista los usuarios disponibles. Se usa para poblar el <select> del formulario.
     *
     * @return array<int, array{id: int, name: string, email: string}>
     *
     * @throws UserServiceUnavailableException si el user-service no está alcanzable
     * @throws UserServiceErrorException si el user-service responde con error
     */
    public function all(): array
    {
        $response = $this->request('/api/users');

        if ($response->failed()) {
            throw new UserServiceErrorException(
                "El user-service respondió {$response->status()} al listar los usuarios."
            );
        }

        return $response->json('data', []);
    }

    /**
     * Ejecuta la petición HTTP con la política de timeout compartida.
     *
     * @throws UserServiceUnavailableException
     */
    private function request(string $uri): Response
    {
        try {
            return Http::acceptJson()
                ->timeout($this->timeout())
                ->get($this->baseUrl().$uri);
        } catch (ConnectionException $exception) {
            throw new UserServiceUnavailableException(
                'No se pudo contactar al user-service.',
                previous: $exception,
            );
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.user_service.url'), '/');
    }

    private function timeout(): int
    {
        return (int) config('services.user_service.timeout');
    }
}
