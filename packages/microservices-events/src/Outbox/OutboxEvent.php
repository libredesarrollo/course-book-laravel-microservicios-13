<?php

namespace Demo\Microservices\Events\Outbox;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Un evento de dominio ya emitido, esperando reparto.
 *
 * El modelo lo usan el productor (para grabar, dentro de su transacción) y
 * el dispatcher del command `outbox:dispatch`. El precio del outbox es que
 * un evento puede re-llegar: la ID del lado receptor es este event_id.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property string|null $trace_id
 * @property Carbon $occurred_at
 * @property Carbon $available_at
 */
#[Fillable([
    'event_id',
    'type',
    'payload',
    'trace_id',
    'occurred_at',
    'available_at',
])]
class OutboxEvent extends Model
{
    public function deliveries(): HasMany
    {
        return $this->hasMany(OutboxDelivery::class, 'outbox_event_id');
    }

    /**
     * Graba el evento en el outbox. Debe llamarse DENTRO de la transacción
     * del negocio: ese es el contrato del patrón (si el commit no ocurrió,
     * el evento tampoco).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function record(string $type, array $payload, ?string $traceId = null): self
    {
        return self::query()->create([
            'event_id' => (string) Str::uuid(),
            'type' => $type,
            'payload' => $payload,
            'trace_id' => $traceId,
            'occurred_at' => now(),
            'available_at' => now(),
        ]);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'trace_id' => 'string',
            'occurred_at' => 'datetime',
            'available_at' => 'datetime',
        ];
    }
}
