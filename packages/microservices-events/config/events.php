<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reparto de eventos
    |--------------------------------------------------------------------------
    |
    | Mecánica del dispatcher; los SUBSCRIPTORES concretos los declara cada
    | aplicación productora en su propio config/events.php (clave subscribers):
    |
    | 'subscribers' => [
    |     'billing' => [
    |         'url'    => env('BILLING_EVENTS_URL', 'http://127.0.0.1:8002/api/events'),
    |         'secret' => env('BILLING_EVENTS_SECRET'),
    |         'events' => ['task.completed'],
    |     ],
    | ],
    |
    | El receptor usa la MISMA clave del config, con 'secret'=>'...' de su lado.
    |
    */

    'timeout' => (int) env('EVENTS_TIMEOUT', 3),

    'max_attempts' => (int) env('EVENTS_MAX_ATTEMPTS', 5),

    'backoff' => [10, 30, 120],

    // Ventana de tolerancia para reclamar re-emisiones con timestamp viejo.
    'max_age' => (int) env('EVENTS_MAX_AGE', 120),

];
