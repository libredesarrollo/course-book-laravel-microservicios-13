<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vida de los tokens de API
    |--------------------------------------------------------------------------
    |
    | Los tokens son opacos y se guardan como SHA-256, así que la única forma
    | de invalidar uno es que expire o que se borre la fila. Sin caducidad, un
    | token robado sirve para siempre: por eso hay un TTL.
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
