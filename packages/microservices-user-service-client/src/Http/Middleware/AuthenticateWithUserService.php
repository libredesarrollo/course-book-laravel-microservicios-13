<?php

namespace Demo\Microservices\UserServiceClient\Http\Middleware;

use Closure;
use Demo\Microservices\UserServiceClient\AuthenticatedUser;
use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;
use Demo\Microservices\UserServiceClient\TokenSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica al cliente preguntándoselo al user-service.
 *
 * El consumidor no tiene una tabla de usuarios, así que no puede validar
 * un token por su cuenta: se lo reenvía al user-service y le pregunta quién
 * es. Es el equivalente a la introspección de tokens de un identity provider.
 *
 * Consecuencia directa: si el user-service está caído, el consumidor deja
 * de poder autenticar a nadie. Ese acoplamiento es inherente al diseño
 * "validar por HTTP" y es el trade-off que evita un JWT stateless. Los que
 * lo suavizan viven en el paquete: reintentos, circuit breaker y esta
 * caché corta del resultado de verificación, para no repetir el salto HTTP
 * en cada página que el usuario abre.
 *
 * Vive en el paquete porque es la última pieza de la frontera: el cliente
 * HTTP habla con el peer, el contrato abstrae esa frontera y este middleware
 * la engancha al ciclo de vida de una petición de Laravel.
 */
class AuthenticateWithUserService
{
    private const USER_CACHE_PREFIX = 'user-service-client.auth.';

    public function __construct(
        private readonly IdentityProvider $users,
        private readonly Redirector $redirector,
        private readonly UrlGenerator $url,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // dd(true);
        $token = $request->bearerToken()
            ?? ($request->hasSession() ? $request->session()->get(TokenSession::SESSION_KEY) : null);

        if (! is_string($token) || $token === '') {
            return $this->reject($request);
        }

        $user = $this->resolveUser($token);

        if ($user === null) {
            // Token caducado o revocado: la sesión web tampoco sirve ya.
            // Ojo: las rutas /api/* no arrancan sesión, así que hay que
            // preguntar antes de tocarla.
            if ($request->hasSession()) {
                $request->session()->forget(TokenSession::SESSION_KEY);
            }

            return $this->reject($request);
        }

        $request->attributes->set(
            AuthenticatedUser::ATTRIBUTE,
            AuthenticatedUser::fromArray($user)
        );

        return $next($request);
    }

    /**
     * Resuelve quién es el dueño del token, con caché corta.
     *
     * Sin caché, cada página accedida por cada usuario del RP cuesta un
     * salto de red al IdP. Guardar la verificación unos segundos solo vale
     * si el precio queda declarado: hasta `cache_ttl` segundos de retraso
     * para notar una revocación. También se recuerda el resultado negativo,
     * para que un token podrido no repita su 401 contra el IdP.
     *
     * @return array{id: int, name: string, email: string}|null
     */
    private function resolveUser(string $token): ?array
    {
        $ttl = max(0, (int) config('user-service-client.cache_ttl', 60));

        if ($ttl === 0) {
            return $this->users->authenticate($token);
        }

        $key = self::USER_CACHE_PREFIX.hash('sha256', $token);

        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached['user'] ?? null;
        }

        $user = $this->users->authenticate($token);

        Cache::put($key, ['user' => $user], now()->addSeconds($ttl));

        return $user;
    }

    /**
     * Responde 401 en la API y manda al inicio del flujo SSO en la web.
     *
     * Con el IdP centralizado, ningún RP debe tener su propia pantalla de login.
     * Si no hay ruta de inicio SSO configurada, el middleware se comporta como
     * uno de API pura.
     */
    private function reject(Request $request): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return $this->unauthenticated();
        }

        $startRoute = config('user-service-client.sso_start_route');

        if (! is_string($startRoute) || $startRoute === '') {
            return $this->unauthenticated();
        }

        return $this->redirector->guest($this->url->route($startRoute));
    }

    private function unauthenticated(): JsonResponse
    {
        return new JsonResponse([
            'error' => 'Unauthenticated.',
            'message' => 'Envía un token válido como: Authorization: Bearer <token>',
        ], 401);
    }
}
