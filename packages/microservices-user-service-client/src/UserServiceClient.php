<?php

namespace Demo\Microservices\UserServiceClient;

use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;
use Demo\Microservices\UserServiceClient\Exceptions\UserServiceErrorException;
use Demo\Microservices\UserServiceClient\Exceptions\UserServiceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP del user-service.
 *
 * Todo el conocimiento sobre "cómo se habla con el user-service" vive aquí:
 * la URL base (config/user-service-client.php), el timeout, el manejo de
 * errores de red y la forma del JSON. Los controllers no saben nada del tema.
 *
 * Es exactamente el patrón de un API Gateway / service client: encapsular la
 * frontera de red para que el resto de la aplicación trate al otro
 * microservicio como si fuera una función local.
 */
class UserServiceClient implements IdentityProvider
{
    /**
     * Resuelve quién es el dueño de un token opaco.
     *
     * El task-service NO tiene la tabla de tokens — vive en el user-service.
     * Por eso cada request autenticado cuesta un salto de red. Ese es el
     * precio de no duplicar el estado de identidad, y es exactamente el
     * trade-off que un JWT stateless viene a evitar.
     *
     * @return array{id: int, name: string, email: string}|null null si el token no es válido
     *
     * @throws UserServiceUnavailableException si el user-service no está alcanzable
     * @throws UserServiceErrorException si el user-service responde con error
     */
    public function authenticate(string $token): ?array
    {
        $response = $this->request('/api/user', ['Authorization' => "Bearer {$token}"]);

        // 401 no es un fallo del servicio: es "este token no vale".
        if ($response->status() === 401) {
            return null;
        }

        if ($response->failed()) {
            throw new UserServiceErrorException(
                "El user-service respondió {$response->status()} al validar el token."
            );
        }

        return $response->json();
    }

    /**
     * Pide un token a cambio de credenciales. Lo usa el login del task-service.
     *
     * @param  array{email: string, password: string, device_name?: string}  $credentials
     *
     * @throws UserServiceUnavailableException
     * @throws UserServiceErrorException si las credenciales son incorrectas (401)
     */
    public function issueToken(array $credentials): string
    {
        $response = $this->post('/api/tokens', $credentials);

        if ($response->failed()) {
            throw new UserServiceErrorException(
                "El user-service rechazó el inicio de sesión ({$response->status()})."
            );
        }

        return $response->json('token');
    }

    /**
     * Revoca un token en el user-service.
     *
     * @throws UserServiceUnavailableException
     * @throws UserServiceErrorException
     */
    public function revokeToken(string $token): void
    {
        $this->delete('/api/tokens', ['Authorization' => "Bearer {$token}"]);
    }

    /**
     * Ejecuta la petición HTTP con la política de timeout compartida.
     *
     * @param  array<string, string>  $headers
     *
     * @throws UserServiceUnavailableException
     */
    private function request(string $uri, array $headers = []): Response
    {
        $headers = array_merge($this->traceHeaders(), $headers);

        return $this->send(fn () => Http::acceptJson()
            ->withHeaders($headers)
            ->timeout($this->timeout())
            ->get($this->baseUrl().$uri));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     *
     * @throws UserServiceUnavailableException
     */
    private function post(string $uri, array $payload = [], array $headers = []): Response
    {
        $headers = array_merge($this->traceHeaders(), $headers);

        return $this->send(fn () => Http::acceptJson()
            ->asJson()
            ->withHeaders($headers)
            ->timeout($this->timeout())
            ->post($this->baseUrl().$uri, $payload));
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws UserServiceUnavailableException
     */
    private function delete(string $uri, array $headers = []): Response
    {
        $headers = array_merge($this->traceHeaders(), $headers);

        return $this->send(fn () => Http::acceptJson()
            ->withHeaders($headers)
            ->timeout($this->timeout())
            ->delete($this->baseUrl().$uri));
    }

    /**
     * @param  callable(): Response  $callback
     *
     * @throws UserServiceUnavailableException
     */
    private function send(callable $callback): Response
    {
        try {
            return $callback();
        } catch (ConnectionException $exception) {
            throw new UserServiceUnavailableException(
                'No se pudo contactar al user-service.',
                previous: $exception,
            );
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('user-service-client.url'), '/');
    }

    private function timeout(): int
    {
        return (int) config('user-service-client.timeout');
    }

    /**
     * Propaga el correlation ID recibido del cliente al user-service.
     */
    private function traceHeaders(): array
    {
        $headers = [];

        if (function_exists('request')) {
            $req = request();
            if (! $req) {
                return $headers;
            }

            $requestId = null;

            if ($req->hasHeader('X-Request-Id')) {
                $requestId = $req->header('X-Request-Id');
            } elseif ($req->attributes && $req->attributes->has('request_id')) {
                $requestId = $req->attributes->get('request_id');
            }

            if (is_string($requestId) && $requestId !== '') {
                $headers['X-Request-Id'] = $requestId;
            }
        }

        return $headers;
    }
}
