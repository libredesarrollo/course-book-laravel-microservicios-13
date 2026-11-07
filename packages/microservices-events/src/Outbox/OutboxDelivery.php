<?php

namespace Demo\Microservices\Events\Outbox;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * El estado de UNA entrega hacia UN subscriptor. Vive separado del evento:
 * el evento fue grabado una vez y ya no se toca; las entregas siguen su
 * propio ritmo (reintentos, backoff, agotamiento).
 *
 * @property int $id
 * @property int $outbox_event_id
 * @property string $subscriber
 * @property string $status pending|delivered|failed
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon $available_at
 * @property Carbon|null $delivered_at
 */
#[Fillable([
    'outbox_event_id',
    'subscriber',
    'status',
    'attempts',
    'last_error',
    'delivered_at',
    'available_at',
])]
class OutboxDelivery extends Model
{
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'delivered_at' => 'datetime',
            'available_at' => 'datetime',
        ];
    }
}
