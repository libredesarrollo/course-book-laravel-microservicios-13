<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SsoController;
use App\Http\Controllers\UserController;
use Demo\Microservices\Observability\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health check (operabilidad). Público. El controller viene del paquete de
// observabilidad: es la misma respuesta en todos los microservicios.
Route::get('/health', [HealthController::class, 'index']);

// Público: emitir tokens. throttle:login protege contra fuerza bruta con dos
// contadores (por IP y por cuenta); ver AppServiceProvider.
Route::post('/tokens', [AuthController::class, 'store'])->middleware('throttle:login');
Route::post('/sso/exchange', [SsoController::class, 'exchange']);

Route::middleware('auth.api-token')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::delete('/tokens', [AuthController::class, 'destroy']);
});

// Catálogo de usuarios. Se deja abierto a propósito: el listado no es un dato
// sensible y lo consume la UI. Si en un sistema real lo fuera, se protege.
Route::get('/users', [UserController::class, 'index']);
Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');
