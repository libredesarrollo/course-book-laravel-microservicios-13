<?php

use App\Http\Controllers\Api\TaskController as ApiTaskController;
use Demo\Microservices\Observability\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health check (operabilidad). Público. El controller viene del paquete de
// observabilidad: es la misma respuesta en todos los microservicios.
Route::get('/health', [HealthController::class, 'index']);

// Todo el recurso es privado: el middleware reenvía el token al user-service
// por HTTP para saber quién llama antes de dejar pasar la petición.
Route::middleware('auth.user-service')->group(function () {
    // Se prefija con api. para no chocar con los nombres de routes/web.php
    // (tasks.index, tasks.store, ... ya están registrados por la UI).
    Route::apiResource('tasks', ApiTaskController::class)->names('api.tasks');
});
