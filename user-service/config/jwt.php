<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modo de token: jwt | opaque
    |--------------------------------------------------------------------------
    |
    | jwt:    Los endpoints de emisión (POST /api/tokens, POST /api/sso/exchange)
    |         entregan un JWT firmado con RS256. Los relying parties lo validan
    |         localmente con la clave pública publicada en /api/jwks.json:
    |         cero saltos de red por request. La revocación es la honesta del
    |         stateless: expiración corta en vez de borrado de fila.
    |
    | opaque: El comportamiento de toda la serie hasta b0.6. Token aleatorio
    |         guardado como SHA-256 en api_tokens; cada RP consulta
    |         GET /api/user para saber quién llama.
    |
    */

    'mode' => env('TOKEN_MODE', 'jwt'),

    /*
    |--------------------------------------------------------------------------
    | Claves de firma
    |--------------------------------------------------------------------------
    |
    | Rutas de los PEM generados con `php artisan user-service:jwt-keys`.
    | La privada NUNCA sale de este servicio ni del disco; los relying
    | parties solo ven la pública, publicada por el endpoint JWKS.
    |
    | kid: identificador de la clave incluido en el header del JWT. Se deriva
    | del hash de la clave pública, así que es estable mientras no se
    | regenere el par: permite a los RP cachear la JWKS y rotar claves sin
    | tirar el cache con cada firma.
    |
    */

    'private_key' => env('JWT_PRIVATE_KEY', storage_path('app/jwt/jwt-private.pem')),

    'public_key' => env('JWT_PUBLIC_KEY', storage_path('app/jwt/jwt-public.pem')),

    /*
    |--------------------------------------------------------------------------
    | Vida del access token
    |--------------------------------------------------------------------------
    |
    | Stateless significa que un token robado vive hasta expirar: por eso el
    | TTL configurable debe ser corto, en contra del TTL del modo opaco
    | (TOKEN_TTL_MINUTES, 60), que se puede revocar borrando la fila.
    |
    */

    'ttl_minutes' => (int) env('JWT_TTL_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Issuer / leeway
    |--------------------------------------------------------------------------
    |
    | iss: valor comparado contra el claim `iss` de cada JWT por los RP.
    | leeway: segundos de desvio de reloj tolerado entre servicios al
    | verificar exp/nbf, para no rechazar tokens legítimos por diferencia
    | de minutos entre máquinas.
    |
    */

    'issuer' => env('JWT_ISSUER', 'user-service'),

    'leeway' => (int) env('JWT_LEEWAY', 10),

];
