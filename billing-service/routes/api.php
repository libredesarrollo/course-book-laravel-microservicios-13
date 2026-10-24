<?php

use App\Http\Controllers\Api\InvoiceController;
use Demo\Microservices\Observability\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

Route::middleware('auth.user-service')->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index']);
});
