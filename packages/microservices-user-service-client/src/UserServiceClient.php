<?php

namespace Demo\Microservices\UserServiceClient;

use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;
use Demo\Microservices\UserServiceClient\Exceptions\UserServiceErrorException;
use Demo\Microservices\UserServiceClient\Exceptions\UserServiceUnavailableException;
use Demo\Microservices\UserServiceClient\Resiliency\CircuitBreaker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP del user-service.
 *
 * Todo el conocimiento sobre "cómo se habla con el user-service" vive aquí:
 * la URL base (config/user-service-client.php), el timeout, el manejo de
 * errores de red, la política de reintentos, el circuit breaker y la forma
 * del JSON. Los controllers no saben nada del tema.
 *
 * Es exactamente el patrón de un API Gateway / service client: encapsular la
 * frontera de red para que el resto de la aplicación trate al otro
 * microservicio como si fuera una función local.
 */
class UserServiceClient implements IdentityProvider
{
    public function __construct(private readonly CircuitBreaker $breaker) {}

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
     * Intercambia un código SSO de un solo uso por un token para el cliente.
     *
     * @return array{token: string, token_type: string, expires_at: string, user: array{id: int, name: string, email: string, created_at: string|null, updated_at: string|null}}|null
     *
     * @throws UserServiceUnavailableException
     * @throws UserServiceErrorException
     */
    public function exchangeCode(string $code, string $client): ?array
    {
        $response = $this->post('/api/sso/exchange', [
            'code' => $code,
            'client' => $client,
        ]);

        if ($response->status() === 400 || $response->status() === 404 || $response->status() === 422 || $response->status() === 401) {
            return null;
        }

        if ($response->failed()) {
            throw new UserServiceErrorException(
                "El user-service rechazó el intercambio SSO ({$response->status()})."
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * Ejecuta la petición HTTP con la política de timeout compartida. Es
     * idempotente: GET se puede repetir sin cambiar nada en el IdP.
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
            ->get($this->baseUrl().$uri), true);
    }

    /**
     * POST no es idempotente para este cliente: el cuerpo de /api/sso/exchange
     * es un código de un solo uso (repetirlo da un 400 engañoso) y /api/tokens
     * emite tokens. No se reintentan; si cae, que caiga en frío.
     *
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
     * DELETE con token de revocación: idempotente. Repetirlo es inofensivo.
     *
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
            ->delete($this->baseUrl().$uri), true);
    }

    /**
     * Ejecuta la petición con la política completa de resiliencia:
     * circuit breaker + reintentos idempotentes.
     *
     * - Si el breaker está abierto, se lanza `UserServiceUnavailableException`
     *   al instante: fallar en milisegundos en vez de esperar el timeout.
     * - Cada intento que no logra conectar, y cada 5xx que se agota en
     *   reintentos, son evidencia para el breaker. Toda respuesta recibida
     *   (incluso un 4xx) prueba que el IdP está vivo y lo cierra.
     * - Un 4xx se devuelve tal cual (es respuesta legítima). Un 5xx se
     *   reintentará solo si es idempotente: el POST devuelve tal cual.
     *
     * @param  callable(): Response  $callback
     *
     * @throws UserServiceUnavailableException
     */
    private function send(callable $callback, bool $retryable = false): Response
    {
        if ($this->breaker->isBlocked()) {
            throw new UserServiceUnavailableException(
                'El user-service no está disponible (circuito abierto).'
            );
        }

        $attempts = $retryable
            ? 1 + max(0, (int) config('user-service-client.retries', 2))
            : 1;
        $delayMs = max(0, (int) config('user-service-client.retry_delay_ms', 150));

        for ($attempt = 1; ; $attempt++) {
            $last = $attempt === $attempts;

            try {
                $response = $callback();
            } catch (ConnectionException $exception) {
                // Cada intento de conexión fallido es evidencia adicional.
                $this->breaker->recordFailure();

                if ($last) {
                    throw new UserServiceUnavailableException(
                        'No se pudo contactar al user-service.',
                        previous: $exception,
                    );
                }

                usleep($delayMs * 1000);

                continue;
            }

            // El circuito solo se preocupa por la infraestructura: hasta un
            // 4xx demuestra que el IdP está vivo y responde.
            if ($response->successful() || $response->clientError()) {
                $this->breaker->recordSuccess();

                return $response;
            }

            if ($last || ! $retryable) {
                if ($response->serverError()) {
                    $this->breaker->recordFailure();
                }

                return $response;
            }

            usleep($delayMs * 1000);
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

            if (method_exists($req, 'hasHeader') && $req->hasHeader('X-Request-Id')) {
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
