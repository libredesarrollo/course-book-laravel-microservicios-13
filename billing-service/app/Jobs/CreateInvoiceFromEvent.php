<?php

namespace App\Jobs;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Crea la factura de una tarea completada, en la cola de facturas.
 *
 * Es el "trabajo pesado" que la cola saca del request HTTP: el receptor
 * responde 202 en milésimas; este insert puede tardar lo suyo, reintentar y
 * fallar a su ritmo, sin que nadie quede esperando del otro lado.
 *
 * IDEMPOTENCIA: el evento llega AT-LEAST-ONCE. La llave es event_id
 * (UNIQUE en invoices). La segunda corrida del mismo evento choca con la
 * restricción y termina en log info, no en una segunda factura.
 */
class CreateInvoiceFromEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $eventId,
        public readonly array $payload,
    ) {}

    public function handle(): void
    {
        $taskId = isset($this->payload['task_id']) ? (int) $this->payload['task_id'] : null;
        $userId = (int) ($this->payload['user_id'] ?? 0);
        $taskTitle = (string) ($this->payload['task_title'] ?? '');

        if ($userId < 1) {
            Log::warning('Evento task.completed sin user_id; no factura', ['event_id' => $this->eventId]);

            return;
        }

        try {
            $invoice = Invoice::query()->create([
                'user_id' => $userId,
                'event_id' => $this->eventId,
                'task_id' => $taskId,
                'task_title' => $taskTitle !== '' ? $taskTitle : 'Tarea',
                'amount' => (float) config('events.charge_per_task', 10.0),
                'status' => Invoice::STATUS_PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            // El mismo evento ya facturó: es la contraparte del
            // at-least-once. Log, ACK y fuera.
            Log::info('Factura ya existía para este evento; sin duplicar', [
                'event_id' => $this->eventId,
                'user_id' => $userId,
            ]);

            return;
        }

        Log::info('Factura creada desde evento', [
            'invoice_id' => $invoice->id,
            'event_id' => $this->eventId,
            'user_id' => $userId,
            'task_id' => $taskId,
        ]);
    }
}
