<?php

namespace App\Services;

use App\Jobs\SendTaskCompletedEmail;
use App\Models\Task;
use Demo\Microservices\Events\Outbox\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El pasillo de negocio de "completar una tarea".
 *
 * La pieza central del capítulo de colas y eventos está aquí: el evento
 * `task.completed` se graba en el OUTBOX en la MISMA transacción que el
 * update de la tarea. Tres consecuencias exactas:
 *
 * - Si la transacción se revierte (falló el update), el evento nunca existió:
 *   nadie factura por una tarea que no se completó.
 * - Si la app muere justo después del commit, el evento queda pendiente y el
 *   `outbox:dispatch` lo reparte al reiniciar: no se pierde por un deploy.
 * - Si el receptor cae, la entrega vive su vida de reintentos APARTE: el
 *   usuario recibe su 200 y no espera a billing.
 *
 * @phpstan-type Actor array{id: int, name: string, email: string}
 */
class TaskCompletion
{
    /**
     * Completa la tarea: aplica los atributos validados (además del status)
     * y emite `task.completed` en la misma transacción.
     *
     * @param  Actor  $actor  el usuario dueño que completa la tarea
     * @param  array<string, mixed>  $attributes  atributos validados a aplicar
     */
    public function complete(Task $task, array $actor, array $attributes = [], ?string $traceId = null): void
    {
        // Ya está completada: es idempotente (PATCH repetido no re-emite).
        if ($task->status === Task::STATUS_COMPLETED) {
            return;
        }

        DB::transaction(function () use ($task, $actor, $attributes, $traceId): void {
            $task->update([...$attributes, 'status' => Task::STATUS_COMPLETED]);

            OutboxEvent::record('task.completed', [
                'task_id' => $task->id,
                'task_title' => $task->title,
                'user_id' => $task->user_id,
                'actor' => $actor,
            ], $traceId);
        });

        // El email va en la cola de siempre (jobs del propio Laravel):
        // con QUEUE_CONNECTION=sync sale en línea; con database, un worker
        // lo saca luego. En ambos casos, el request HTTP ya no lo espera.
        SendTaskCompletedEmail::dispatch($task->fresh(), $actor)->onQueue('mails');

        Log::info('Tarea completada; evento en el outbox', [
            'task_id' => $task->id,
            'user_id' => $task->user_id,
            'trace_id' => $traceId,
        ]);
    }
}
