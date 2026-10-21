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

];
