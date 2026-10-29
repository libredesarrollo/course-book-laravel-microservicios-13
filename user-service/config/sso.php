<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clientes SSO autorizados
    |--------------------------------------------------------------------------
    |
    | Cada cliente debe tener su redirect_uri exacta o lista de variantes
    | permitidas (127.0.0.1 / localhost con puerto). El IdP rechaza
    | cualquier otro destino.
    */

    'clients' => [
        'task' => [
            'http://127.0.0.1:8001/sso/callback',
            'http://localhost:8001/sso/callback',
        ],
        'billing' => [
            'http://127.0.0.1:8002/sso/callback',
            'http://localhost:8002/sso/callback',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | TTL del ticket
    |--------------------------------------------------------------------------
    */

    'ticket_ttl' => (int) env('SSO_TICKET_TTL', 60),

];
