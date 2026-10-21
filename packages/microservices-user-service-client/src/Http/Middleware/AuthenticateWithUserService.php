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
 * "validar por HTTP" y es el trade-off que evita un JWT stateless.
 *
 * Vive en el paquete porque es la última pieza de la frontera: el cliente
 * HTTP habla con el peer, el contrato abstrae esa frontera y este middleware
 * la engancha al ciclo de vida de una petición de Laravel.
 */
class AuthenticateWithUserService
{
    public function __construct(
        private readonly IdentityProvider $users,
        private readonly Redirector $redirector,
        private readonly UrlGenerator $url,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken()
            ?? ($request->hasSession() ? $request->session()->get(TokenSession::SESSION_KEY) : null);

        if (! is_string($token) || $token === '') {
            return $this->reject($request);
        }

        $user = $this->users->authenticate($token);

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
     * Responde 401 en la API y manda a la pantalla de login en la web.
     *
     * La ruta de login no se puede hardcodear en un paquete: la aplicación
     * la declara en su configuración. Si no declara ninguna, el middleware
     * se comporta como uno de API pura y siempre responde 401.
     */
    private function reject(Request $request): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return $this->unauthenticated();
        }

        $loginRoute = config('user-service-client.login_route');

        if (! is_string($loginRoute) || $loginRoute === '') {
            return $this->unauthenticated();
        }

        return $this->redirector->guest($this->url->route($loginRoute));
    }

    private function unauthenticated(): JsonResponse
    {
        return new JsonResponse([
            'error' => 'Unauthenticated.',
            'message' => 'Envía un token válido como: Authorization: Bearer <token>',
        ], 401);
    }
}
