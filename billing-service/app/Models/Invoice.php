<?php

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una factura de billing-service.
 *
 * @property int $id
 * @property int $user_id
 * @property string $event_id
 * @property int|null $task_id
 * @property string $task_title
 * @property float $amount
 * @property string $status
 */
#[Fillable([
    'user_id',
    'event_id',
    'task_id',
    'task_title',
    'amount',
    'status',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'task_id' => 'integer',
            'amount' => 'float',
        ];
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
