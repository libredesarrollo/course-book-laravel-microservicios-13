<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Patrón outbox: los eventos de dominio se escriben en ESTA MISMA base y en
| la MISMA transacción que el dato de negocio. Si la transacción se revierte,
| el evento nunca existió; si la app muere después del commit, el evento
| queda pendiente y el dispatcher lo reparte cuando vuelva.
|
| Dos tablas, porque el estado de la ENTREGA no es el del EVENTO: el evento
| se graba una vez; cada subscriptor entrega a su ritmo (reintentos,
| backoff) sin afectar a los demás.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            // Identidad del EVENTO: es lo que el receptor usa para ser
            // idempotente (la re-entrega llega con el mismo event_id).
            $table->uuid('event_id')->unique();
            $table->string('type');
            $table->json('payload');
            // Trazabilidad: el request HTTP que produjo el evento, para
            // seguir el hilo en los logs (X-Request-Id de observabilidad).
            $table->string('trace_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('available_at')->index();
            $table->timestamps();
        });

        Schema::create('outbox_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outbox_event_id')->constrained('outbox_events')->cascadeOnDelete();
            $table->string('subscriber');
            // pending / delivered / failed. at-least-once: entregado puede
            // darse por hecho una vez, pendiente vuelve a su hora.
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamp('available_at')->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            // Un subscriptor entrega cada evento una sola vez.
            $table->unique(['outbox_event_id', 'subscriber']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_deliveries');
        Schema::dropIfExists('outbox_events');
    }
};
