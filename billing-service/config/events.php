<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Receptor de eventos de dominio
    |--------------------------------------------------------------------------
    |
    | billing es CONSUMIDOR, no productor: no declara subscriptores ni lleva
    | outbox. La única configuración propia del capítulo es el secreto
    | compartido que usa el middleware de verificación (del paquete
    | demo/microservices-events) para validar la firma HMAC del cuerpo.
    |
    | Debe ser el mismo valor que el task-service tiene en
    | BILLING_EVENTS_SECRET. Si rota, rota de los dos lados.
    |
    */

    'secret' => env('TASK_EVENTS_SECRET'),

    /*
    | Importe de demo por TAREA COMPLETADA. Un sistema real sacaría el
    | importe del catálogo y de la concertación con el dominio; aquí la
    | mesa del ejemplo es fija para que el flujo se pueda reproducir.
    */
    'charge_per_task' => (float) env('BILLING_CHARGE_PER_TASK', 10.0),

];
