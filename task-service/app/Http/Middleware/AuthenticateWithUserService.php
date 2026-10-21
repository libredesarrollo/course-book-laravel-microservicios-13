<?php

namespace App\Http\Middleware;

use App\Support\AuthenticatedUser;
use Closure;
use Demo\Microservices\UserServiceClient\Contracts\IdentityProvider;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica al cliente preguntándoselo al user-service.
 *
 * El task-service no tiene una tabla de usuarios, así que no puede validar
 * un token por su cuenta: se lo reenvía al user-service y le pregunta quién
 * es. Es el equivalente a la introspección de tokens de un identity provider.
 *
 * Consecuencia directa: si el user-service está caído, el task-service
 * deja de poder autenticar a nadie. Ese acoplamiento es inherente al diseño
 * "validar por HTTP" y es el trade-off que evita un JWT stateless.
 */
class AuthenticateWithUserService
{
    public function __construct(private readonly IdentityProvider $users) {}

    public function handle(Request $request, Closure $next): Response
    {

        $token = $request->bearerToken()
            ?? ($request->hasSession() ? $request->session()->get('api_token') : null);

        if (! is_string($token) || $token === '') {
            return $this->reject($request);
        }

        $user = $this->users->authenticate($token);

        if ($user === null) {
            // Token caducado o revocado: la sesión web tampoco sirve ya.
            // Ojo: las rutas /api/* no arrancan sesión, así que hay que
            // preguntar antes de tocarla.
            if ($request->hasSession()) {
                $request->session()->forget('api_token');
            }

            return $this->reject($request);
        }

        $request->attributes->set('authenticated_user', AuthenticatedUser::fromArray($user));

        return $next($request);
    }

    private function reject(Request $request): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'error' => 'Unauthenticated.',
                'message' => 'Envía un token válido como: Authorization: Bearer <token>',
            ], 401);
        }

        return redirect()->guest(route('login'));
    }
}
