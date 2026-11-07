<?php

use Demo\Microservices\Events\Console\DispatchOutbox;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
|
| El dispatcher del outbox corre con frecuencia pequeña porque es barato:
| consulta la tabla pendiente (índice available_at) y se va. Tener una
| corrida por minuto garantiza que un evento no vive más que eso en el
| outbox salvo bug o caída del receptor.
|
| `withoutOverlapping` evita que dos corridas sobrepongan la entrega del
| mismo evento si una se demora.
|
*/

Schedule::command(DispatchOutbox::class)->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
