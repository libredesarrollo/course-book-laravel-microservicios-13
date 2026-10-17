<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica la petición contra la tabla api_tokens de ESTE servicio.
 *
 * El task-service reenvía aquí el token que le llega del cliente, porque es el
 * user-service —y no el task-service— quien tiene la tabla de tokens.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = ApiToken::findByPlainText($request->bearerToken());

        if ($token === null) {
            return $this->unauthorized();
        }

        // Guardamos el modelo en el request para que los controllers no
        // vuelvan a consultar la base de datos.
        $request->attributes->set('api_token', $token);
        $request->attributes->set('user', $token->user);

        $token->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'error' => 'Unauthenticated.',
            'message' => 'Falta un token válido. Envíalo como: Authorization: Bearer <token>',
        ], 401);
    }
}
