<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Las existencias reales: hasta b0.9 las facturas eran un array a mano.
| El event_id es UNIQUE porque es la línea de idempotencia del receptor:
| la re-entrega del mismo evento (at-least-once) choca con esta fila y se
| convierte en "ya procesado", no en una segunda factura.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('event_id')->unique();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->string('task_title');
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
