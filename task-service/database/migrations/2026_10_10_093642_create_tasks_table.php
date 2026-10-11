<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');

            // Referencia lógica al user-service. NO es una llave foránea:
            // la tabla users vive en otra base de datos (db_users), en otro
            // proceso y en otro servicio. La integridad se valida por HTTP.
            $table->unsignedBigInteger('user_id')->index();

            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
