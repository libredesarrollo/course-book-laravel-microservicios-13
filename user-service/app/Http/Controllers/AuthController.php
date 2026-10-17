<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /**
     * Emite un token de API a partir de email + contraseña.
     *
     * POST /api/tokens
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
            return response()->json([
                'error' => 'Credenciales inválidas',
            ], 401);
        }

        $issued = ApiToken::issueFor($user, $validated['device_name'] ?? 'api-token');

        return response()->json([
            'token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['model']->expires_at?->toIso8601String(),
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
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');

        $token->delete();

        return response()->json(status: 204);
    }
}
