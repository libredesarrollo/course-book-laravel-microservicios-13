<?php

namespace App\Http\Controllers;

use App\Services\Jwt\JwtKeys;
use Illuminate\Http\JsonResponse;

/**
 * Publica la clave pública del IdP en formato JWKS.
 *
 * Es el reemplazo del "ve a preguntarle al IdP en cada request". Aquí viene
 * el material de verificación una vez por RP (quedando cacheado con ttl),
 * no la identidad de cada usuario en cada request.
 *
 * Ruta pública a propósito: una clave pública es pública. Lo que protege
 * al sistema es que sin la privada nadie FIRMAR; ver es gratis.
 */
final class JwksController extends Controller
{
    public function index(JwtKeys $keys): JsonResponse
    {
        return response()->json($keys->jwks());
    }
}
