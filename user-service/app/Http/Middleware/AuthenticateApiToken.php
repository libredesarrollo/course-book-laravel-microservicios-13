<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\User;
use App\Services\Jwt\JwtTokenVerifier;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica la petición contra los access tokens de ESTE servicio.
 *
 * Acepta los dos modos, en el orden que importa:
 *
 * - JWT: si el bearer es un JWT (tres segmentos separados por punto) se
 *   verifica con la firma del IdP, igual que haría un RP. Cero consultas a
 *   api_tokens. La revocación del stateless no existe: expira.
 *
 * - Opaco: la fila con SHA-256 en api_tokens. Es el modo de la serie hasta
 *   b0.6 y sigue funcionando — un token opaco emitido antes de activar JWT
 *   sigue siendo válido hasta que expire o se borre.
 *
 * El task-service reenvía aquí el token del cliente —/api/user es la
 * introspección— solo cuando su mode es opaque. Con JWT el RP no pregunta.
 */
final class AuthenticateApiToken
{
    public function __construct(private readonly JwtTokenVerifier $jwtVerifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        $token = ApiToken::findByPlainText($bearer);

        if ($token !== null) {
            // Guardamos el modelo en el request para que los controllers no
            // vuelvan a consultar la base de datos.
            $request->attributes->set('api_token', $token);
            $request->attributes->set('user', $token->user);

            $token->forceFill(['last_used_at' => now()])->save();

            return $next($request);
        }

        $claims = $this->verifyJwt($bearer);

        if ($claims !== null) {
            // El JWT ya trae la identidad (sub). Solo re-boundamos al modelo:
            // un sub con fila borrada no autentica, porque el claim es una
            // foto emitida hace hasta 15 minutos, no hoy.
            $user = User::query()->find((int) $claims['sub']);

            if ($user !== null) {
                $request->attributes->set('jwt_claims', $claims);
                $request->attributes->set('user', $user);

                return $next($request);
            }
        }

        return $this->unauthorized();
    }

    /**
     * ¿El bearer es un JWT del propio IdP? Por forma, no por contenido.
     */
    private function verifyJwt(?string $bearer): ?array
    {
        if ($bearer === null || substr_count($bearer, '.') !== 2) {
            return null;
        }

        return $this->jwtVerifier->verify($bearer);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'error' => 'Unauthenticated.',
            'message' => 'Falta un token válido. Envíalo como: Authorization: Bearer <token>',
        ], 401);
    }
}
