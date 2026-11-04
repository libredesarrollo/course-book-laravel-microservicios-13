<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modo de access token: jwt | opaque
    |--------------------------------------------------------------------------
    |
    | jwt:    POST /api/tokens y POST /api/sso/exchange entregan un JWT
    |         firmado con RS256 (config/jwt.php). Los relying parties lo
    |         verifican localmente con la clave pública de /api/jwks.json:
    |         cero saltos de red por request. La revocación es la del
    |         stateless: expiración corta, no borrado de fila.
    |
    | opaque: La historia de la serie hasta b0.6. Token aleatorio guardado
    |         como SHA-256 en la tabla api_tokens; cada RP pregunta
    |         GET /api/user en cada request (introspección).
     |
     | Ambos modos responden exactamente lo mismo al RP. Los tokens opacos
     | emitidos antes del cambio siguen siendo válidos hasta que expiren o
     | se borren: el middleware los acepta mientras la fila exista.
     |
     */

    'mode' => (string) env('TOKEN_MODE', 'jwt'),

    /*
    |--------------------------------------------------------------------------
    | Vida de los tokens de API
    |--------------------------------------------------------------------------
    |
    | Aplica al modo opaque y al modo jwt por partes:
    |
    |   - opaque: el TTL es LA caducidad; invalidar antes toca borrar la fila.
    |
    |   - jwt:    el TTL del token manda el de config/jwt.php (JWT_TTL_MINUTES,
    |             corto por diseño); este valor aquí solo describe lo que
    |             se emite cuando mode=opaque.
    |
    | No hay refresh tokens todavía. Cuando un token caduca, el usuario vuelve
    | a iniciar sesión con su contraseña. La renovación silenciosa es el paso
    | siguiente, y ya tiene el sitio pensado: un endpoint /api/tokens/refresh.
    |
    */

    'ttl_minutes' => (int) env('TOKEN_TTL_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Esfuerzo de fuerza bruta
    |--------------------------------------------------------------------------
    |
    | Dos límites, porque protegen de cosas distintas:
    |
    |   - por cuenta: frena un ataque dirigido a un email concreto
    |   - por IP:      frena un barrido con muchos emails distintos
    |
    | El que salta el más rápido es el que devuelve 429 primero.
    |
    */

    'throttle' => [
        'per_account' => (int) env('LOGIN_THROTTLE_PER_ACCOUNT', 5),
        'per_ip' => (int) env('LOGIN_THROTTLE_PER_IP', 20),
        'decay_seconds' => (int) env('LOGIN_THROTTLE_DECAY', 60),
    ],

];
