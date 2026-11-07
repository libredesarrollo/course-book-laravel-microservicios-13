<?php

namespace App\Mail;

use App\Models\Task;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Envoltorio visual del correo; la cola la aporta SendTaskCompletedEmail
 * (job en la cola mails, con sus reintentos). El mailable se envía en línea
 * una vez el job corre: mantenerlo ShouldQueue duplicaría el encolado — el
 * framework lo mandaría a la cola default, fuera de nuestra política.
 *
 * @phpstan-type Actor array{id: int, name: string, email: string}
 */
class TaskCompletedMail extends Mailable
{
    use SerializesModels;

    /**
     * @param  Actor  $actor
     */
    public function __construct(
        public readonly Task $task,
        public readonly array $actor,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Tarea completada: {$this->task->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mails.task-completed',
        );
    }
}
