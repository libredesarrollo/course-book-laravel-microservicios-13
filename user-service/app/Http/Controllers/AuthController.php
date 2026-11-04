<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\User;
use App\Services\Jwt\AccessTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function __construct(private readonly AccessTokenIssuer $accessTokens) {}

    /**
     * Emite un token de API a partir de email + contraseña.
     *
     * POST /api/tokens
     *
     * Esta ruta lleva el middleware throttle:login, porque recibe
     * contraseñas. Los dos límites (por IP y por cuenta) están definidos en
     * AppServiceProvider.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        // Mismo mensaje y mismo status para "no existe" y "contraseña mala":
        // si no, esta respuesta sirve para enumerar qué emails están dados de alta.
        if (! $user || ! $user->passwordMatches($validated['password'])) {
            $this->logFailedAttempt($request, $validated['email'], $user !== null);

            return response()->json([
                'error' => 'Credenciales inválidas',
            ], 401);
        }

        $issued = $this->accessTokens->issue($user, $validated['device_name'] ?? 'api-token');

        Log::info('API token issued', $issued['api_token'] === null ? [
            'user_id' => $user->id,
            'token_type' => 'jwt',
            'device' => $validated['device_name'] ?? 'api-token',
            'expires_at' => $issued['expires_at'],
        ] : [
            'user_id' => $user->id,
            'token_type' => 'opaque',
            'token_id' => $issued['api_token']->id,
            'device' => $issued['api_token']->name,
            'expires_at' => $issued['api_token']->expires_at?->toIso8601String(),
        ]);

        return response()->json([
            'token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['expires_at'],
            'user' => $user,
        ], 201);
    }

    /**
     * Responde quién es el dueño del token presentado.
     *
     * GET /api/user
     *
     * Este es el endpoint que el task-service consulta en cada request para
     * saber quién está llamando. Es el equivalente a la introspección de un
     * identity provider: el recurso protegido no guarda usuarios, solo
     * reenvía el token y pregunta.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('user');

        Log::info('Consulta de usuario');

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }

    /**
     * Revoca el token con el que se hizo el request.
     *
     * DELETE /api/tokens
     *
     * Con token opaco borra la fila: una revocación real e inmediata. Con
     * JWT no hay nada que borrar — el stateless no mantiene filas —, así
     * que es un no-op que responde igual (204): la revocación real es la
     * expiración corta del propio token. El trade-off, de frente.
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var ApiToken|null $token */
        $token = $request->attributes->get('api_token');

        if ($token === null) {
            $user = $request->attributes->get('user');

            Log::info('JWT logout: no-op (stateless)', [
                'user_id' => $user instanceof User ? $user->id : null,
            ]);

            return response()->json(status: 204);
        }

        $token->delete();

        Log::info('API token revoked', [
            'token_id' => $token->id,
            'user_id' => $token->user_id,
        ]);

        return response()->json(status: 204);
    }

    /**
     * Deja rastro de cada intento fallido.
     *
     * Sin esto, una fuerza bruta es completamente silenciosa: el único rastro
     * sería el propio 401 en el log de acceso. Con este warning, un pico de
     * 429 en el log ya es una señal de que alguien está probando contraseñas,
     * y se puede correlacionar por IP.
     *
     * $accountExists va al log a propósito y no a la respuesta: sirve para
     * detectar barridos contra emails reales, pero no puede filtrarse al
     * cliente porque el mensaje de error es idéntico en ambos casos.
     */
    private function logFailedAttempt(Request $request, string $email, bool $accountExists): void
    {
        Log::warning('Failed login attempt', [
            'email' => $email,
            'ip' => $request->ip(),
            'account_exists' => $accountExists,
        ]);
    }
}
