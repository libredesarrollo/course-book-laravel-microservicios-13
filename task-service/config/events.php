<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscriptores de eventos de dominio (patrón outbox)
    |--------------------------------------------------------------------------
    |
    | El task-service EMITE `task.completed`; el dispatcher
    | (`php artisan outbox:dispatch`, corrido por schedule) los reparte a
    | cada subscriptor registrado aquí, con HMAC de cuerpo y reintentos del
    | paquete demo/microservices-events.
    |
    | El secreto es compartido CON billing y solo CON billing: cada par
    | productor/receptor lleva el suyo. Cambiar la URL no obliga a tocar
    | código: es configuración.
    |
    */

    'subscribers' => [
        'billing' => [
            'url' => env('BILLING_EVENTS_URL', 'http://127.0.0.1:8002/api/events'),
            'secret' => env('BILLING_EVENTS_SECRET'),
            'events' => ['task.completed'],
        ],
    ],

    // Defaults operativos (timeout/reintentos) viven en el paquete; aquí
    // solo los que esta app quiere ajustar, si algo difiere.

];
