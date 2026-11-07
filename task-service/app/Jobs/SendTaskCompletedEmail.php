<?php

namespace App\Jobs;

use App\Mail\TaskCompletedMail;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * El mail de "tarea completada", en la cola de email (queue 'mails').
 *
 * @phpstan-type Actor array{id: int, name: string, email: string}
 */
class SendTaskCompletedEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 15];

    /**
     * @param  Actor  $actor
     */
    public function __construct(
        public readonly Task $task,
        public readonly array $actor,
    ) {}

    public function handle(): void
    {
        Mail::to($this->actor['email'])
            ->send(new TaskCompletedMail(
                task: $this->task,
                actor: $this->actor,
            ));

        Log::info('Mail de tarea completada despachado', [
            'task_id' => $this->task->id,
            'to' => $this->actor['email'],
        ]);
    }
}
