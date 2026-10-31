<?php

return [

    /*
    |--------------------------------------------------------------------------
    | URL del user-service
    |--------------------------------------------------------------------------
    |
    | Dirección base del microservicio de identidad. El cliente HTTP del
    | paquete usa esta URL para hablar con él. En desarrollo local suele ser
    | http://127.0.0.1:8000; en producción, el nombre de servicio o la URL
    | del balanceador.
    |
    */

    'url' => env('USER_SERVICE_URL', 'http://127.0.0.1:8000'),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | Segundos que se espera al user-service antes de abandonar. Un timeout
    | corto hace que el task-service falle rápido cuando la dependencia no
    | está; uno largo hace que los usuarios esperen. Tres segundos es un
    | punto de partida razonable para una llamada en la misma red.
    |
    */

    'timeout' => (int) env('USER_SERVICE_TIMEOUT', 3),

    /*
    |--------------------------------------------------------------------------
    | Resiliencia
    |--------------------------------------------------------------------------
    |
    | El IdP es la única dependencia que puede tumbar a esta aplicación
    | entera: cada request autenticado pasa por él. Estos valores suavizan
    | ese acoplamiento:
    |
    | retries: reintento por timeout/5xx. Solo se aplica a peticiones
    | idempotentes (GET y DELETE); los POST no se repiten porque el código
    | SSO es de un solo uso. Con 0 queda el comportamiento actual.
    |
    | retry_delay_ms: pausa fija entre reintentos, en milisegundos.
    |
    | cache_ttl: segundos que el middleware considera válido el resultado de
    | verificar un token, para no repetir el salto HTTP en cada página. El
    | precio es una ventana de hasta `cache_ttl` segundos para notar una
    | revocación. Con 0 se verifica por HTTP en cada request.
    |
    | circuit_threshold: fallos consecutivos de infraestructura (timeouts,
    | 5xx) que abren el circuito. De ahí en adelante, fallar cuesta
    | milisegundos en vez de `timeout` segundos.
    |
    | circuit_cooldown: segundos que el circuito permanece abierto antes de
    | volver a dejar pasar tráfico y sondear al IdP.
    |
    */

    'retries' => (int) env('USER_SERVICE_RETRIES', 2),

    'retry_delay_ms' => (int) env('USER_SERVICE_RETRY_DELAY_MS', 150),

    'cache_ttl' => (int) env('USER_SERVICE_CACHE_TTL', 60),

    'circuit_threshold' => (int) env('USER_SERVICE_CIRCUIT_THRESHOLD', 3),

    'circuit_cooldown' => (int) env('USER_SERVICE_CIRCUIT_COOLDOWN', 30),

    /*
    |--------------------------------------------------------------------------
    | Cliente SSO
    |--------------------------------------------------------------------------
    */

    'client' => env('USER_SERVICE_CLIENT', 'app'),

    /*
    |--------------------------------------------------------------------------
    | URL de callback del RP
    |--------------------------------------------------------------------------
    | Si es null, se usa url('/sso/callback').
    */

    'callback_url' => env('USER_SERVICE_CALLBACK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Ruta para iniciar el flujo SSO
    |--------------------------------------------------------------------------
    */

    'sso_start_route' => env('USER_SERVICE_SSO_START_ROUTE', 'sso.start'),

];
