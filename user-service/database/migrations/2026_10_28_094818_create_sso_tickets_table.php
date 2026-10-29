<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'client']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_tickets');
    }
};
