<?php

use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\InvoiceController;
use Demo\Microservices\Observability\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

// El receptor de eventos NO pide token del user-service: su "llave" es la
// firma HMAC del cuerpo (middleware events.verify del paquete de eventos).
Route::post('/events', EventController::class)->middleware('events.verify');

Route::middleware('auth.user-service')->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index']);
});
