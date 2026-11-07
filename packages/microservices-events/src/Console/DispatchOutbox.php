<?php

namespace Demo\Microservices\Events\Console;

use Demo\Microservices\Events\Outbox\OutboxDispatcher;
use Illuminate\Console\Command;

/**
 * El despachador manual del outbox. En producción corre por schedule
 * (cada minuto basta; el outbox fue hecho para tolerar la espera), y en la
 * demo de la serie se corre a mano para ver cada entrega.
 */
class DispatchOutbox extends Command
{
    protected $signature = 'outbox:dispatch {--limit=50 : Máximo de eventos a considerar}';

    protected $description = 'Reparte los eventos pendientes del outbox a los subscriptores configurados';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $begin = microtime(true);

        $delivered = $dispatcher->dispatchPending((int) $this->option('limit'));

        $miliseconds = (int) round((microtime(true) - $begin) * 1000);

        if ($delivered === 0) {
            $this->line('Outbox sin entregas nuevas.');

            return self::SUCCESS;
        }

        $this->info("Entregas nuevas: {$delivered} ({$miliseconds} ms)");

        return self::SUCCESS;
    }
}
