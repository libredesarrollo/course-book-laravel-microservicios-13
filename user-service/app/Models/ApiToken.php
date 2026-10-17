<?php

namespace App\Models;

use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token de API opaco.
 *
 * No es un JWT: es una cadena aleatoria de la que solo guardamos su SHA-256.
 * Eso significa que este servicio DEBE ser consultado para validar cualquier
 * token — es el user-service, no el task-service, quien tiene la tabla.
 */
#[Fillable(['user_id', 'token_hash', 'name', 'expires_at'])]
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Genera un token nuevo y devuelve el texto plano.
     *
     * El plano se entrega una sola vez, en la respuesta HTTP. Aquí solo
     * sobrevive su hash, que es lo que se busca en cada request.
     *
     * @return array{token: string, model: self}
     */
    public static function issueFor(User $user, string $name = 'api-token'): array
    {
        $plain = bin2hex(random_bytes(32));

        $model = $user->tokens()->create([
            'token_hash' => hash('sha256', $plain),
            'name' => $name,
        ]);

        return ['token' => $plain, 'model' => $model];
    }

    /**
     * Busca un token vigente a partir de su texto plano.
     */
    public static function findByPlainText(?string $plainToken): ?self
    {
        if ($plainToken === null || $plainToken === '') {
            return null;
        }

        return static::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainToken))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
