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
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Solo se guarda el hash del token. Si alguien lee esta tabla,
            // no puede suplantar a nadie: el token en claro existe una sola
            // vez, en la respuesta del POST /api/tokens.
            $table->string('token_hash', 64)->unique();

            $table->string('name')->default('api-token');
            $table->timestamp('last_used_at')->nullable();

            // NOT NULL a propósito: un token sin caducidad sirve para siempre,
            // así que el esquema impide que exista ese caso por accidente.
            // ApiToken::issueFor() exige la fecha como parámetro.
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
