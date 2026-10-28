<?php

use App\Http\Controllers\SsoController;
use Demo\Microservices\Observability\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Health check en la raíz, para que el balanceador no tenga que saber que
// las rutas de API van con prefijo. Responde lo mismo que /api/health.
Route::get('/health', [HealthController::class, 'index']);
